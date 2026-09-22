<?php

namespace App\Console\Commands;

use App\Contracts\Data\ModelCapabilities;
use App\Services\Provider\ModelRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Discovers fal's real request and response shapes by making one small
 * generation and recording everything it sends back.
 *
 * This is a diagnostic, not part of the pipeline. It exists because the adapter
 * cannot be written against a guessed payload: this codebase has never been
 * able to reach fal.ai, so the only trustworthy source for those shapes is a
 * real call from an account that can.
 *
 * It spends real money — one short clip — so it asks first and shows the
 * estimate before doing anything.
 */
class CaptureFalShapesCommand extends Command
{
    protected $signature = 'studio:capture-fal-shapes
                            {--model=kling-2-5-turbo-pro : Registry key from config/studio.php video_models}
                            {--endpoint= : Raw provider model id, overriding the registry}
                            {--prompt=A calm river at dawn, slow drifting mist : Prompt for the test clip}
                            {--duration= : Clip length in seconds. Defaults to the model minimum, which is the cheapest probe.}
                            {--resolution= : Send a resolution. Omitted by default — an unaccepted parameter can fail the call.}
                            {--aspect= : Send an aspect ratio. Omitted by default, for the same reason.}
                            {--audio : Ask for native audio. Ignored by models with no audio toggle.}
                            {--poll-interval=5 : Seconds between status checks}
                            {--max-polls=60 : Give up after this many checks}
                            {--dry-run : Print the request and exit without calling anything}
                            {--out= : Directory for the captured JSON}';

    protected $description = 'Capture fal submit/status/result payload shapes from one small real generation';

    protected string $outputDir;

    protected ModelCapabilities $capabilities;

    public function handle(): int
    {
        $key = (string) config('studio.fal.key');
        $base = rtrim((string) config('studio.fal.queue_url'), '/');

        try {
            $this->capabilities = app(ModelRegistry::class)->video((string) $this->option('model'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $model = trim(
            (string) ($this->option('endpoint') ?: $this->capabilities->endpoint),
            '/',
        );

        if ($model === '') {
            $this->components->error(
                "Model '{$this->capabilities->key}' declares no endpoint. ".
                'Add one to config/studio.php, or pass --endpoint.'
            );

            return self::FAILURE;
        }

        if (($durationError = $this->validateDuration()) !== null) {
            $this->components->error($durationError);

            return self::FAILURE;
        }

        $this->outputDir = $this->option('out')
            ?: storage_path('app/private/fal-capture/'.now()->format('Ymd-His'));

        $payload = $this->buildPayload();
        $submitUrl = "{$base}/{$model}";

        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Submit URL</>', $submitUrl);
        $this->components->twoColumnDetail('<fg=cyan>Payload</>', json_encode($payload, JSON_UNESCAPED_SLASHES));
        $this->components->twoColumnDetail('<fg=cyan>Output</>', $this->outputDir);
        $this->line('');

        $this->warn('The URLs above follow fal\'s documented queue pattern but have never been');
        $this->warn('verified from this codebase. A 404 means the pattern is wrong, not that');
        $this->warn('your account is — the model page\'s API tab has the real one, and you can');
        $this->warn('override it with FAL_QUEUE_URL.');
        $this->line('');

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was sent and nothing was charged.');

            return self::SUCCESS;
        }

        if ($key === '') {
            $this->components->error('FAL_KEY is not set. Put it in .env — never on the command line, where it lands in your shell history.');

            return self::FAILURE;
        }

        $seconds = $this->duration();
        $rate = $this->capabilities->costPerSecondUsd(
            withAudio: $this->capabilities->supportsNativeAudioToggle && $this->option('audio'),
        );

        $this->components->warn(sprintf(
            'This makes a real generation on %s: %s at $%.2f/s = ~$%.2f. '.
            'Indicative only — the invoice is the truth.',
            $this->capabilities->label,
            rtrim(rtrim(number_format($seconds, 1), '0'), '.').'s',
            $rate,
            $seconds * $rate,
        ));

        if (! $this->confirm('Spend that and capture the shapes?', false)) {
            $this->components->info('Nothing sent.');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($this->outputDir);
        $this->save('00-request-sent.json', ['url' => $submitUrl, 'payload' => $payload]);

        // ---- 1. Submit -----------------------------------------------------
        $submit = $this->call_('POST', $submitUrl, $key, $payload);

        if ($submit === null) {
            return self::FAILURE;
        }

        $this->save('01-submit.json', $submit->json() ?? ['raw' => $submit->body()]);
        $this->components->info('Submit captured → 01-submit.json');

        $requestId = $this->findRequestId($submit->json() ?? []);

        if ($requestId === null) {
            $this->components->error('Could not find a request id in the submit response.');
            $this->line('Top-level keys were: '.implode(', ', array_keys($submit->json() ?? [])));
            $this->line('01-submit.json has the whole body — send it over and I will map it.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Request id', $requestId);

        // ---- 2. Status -----------------------------------------------------
        $statusUrl = "{$base}/{$model}/requests/{$requestId}/status";
        $capturedInProgress = false;
        $terminal = null;

        for ($poll = 1; $poll <= (int) $this->option('max-polls'); $poll++) {
            $status = $this->call_('GET', $statusUrl, $key);

            if ($status === null) {
                return self::FAILURE;
            }

            $body = $status->json() ?? [];
            $state = $this->describeState($body);

            $this->line("  poll {$poll}: {$state}");

            // Keep the first mid-flight response: the in-progress strings are
            // exactly what checkStatus() has to recognise, and they vanish once
            // the job finishes.
            if (! $capturedInProgress) {
                $this->save('02-status-in-progress.json', $body);
                $capturedInProgress = true;
            }

            if ($this->looksTerminal($state)) {
                $terminal = $body;
                break;
            }

            sleep(max(1, (int) $this->option('poll-interval')));
        }

        if ($terminal === null) {
            $this->components->error('Never reached a terminal status. 02-status-in-progress.json still has a sample.');

            return self::FAILURE;
        }

        $this->save('03-status-complete.json', $terminal);
        $this->components->info('Status captured → 02-status-in-progress.json, 03-status-complete.json');

        // ---- 3. Result -----------------------------------------------------
        $result = $this->call_('GET', "{$base}/{$model}/requests/{$requestId}", $key);

        if ($result === null) {
            return self::FAILURE;
        }

        $this->save('04-result.json', $result->json() ?? ['raw' => $result->body()]);
        $this->components->info('Result captured → 04-result.json');

        $this->summarise($result->json() ?? []);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(): array
    {
        // Deliberately minimal. The point of this command is to discover the
        // response shape, and the likeliest way to fail at that is to send a
        // parameter the model does not accept — which usually comes back as a
        // 4xx that reads like a wrong URL rather than a wrong payload.
        $payload = [
            'prompt' => (string) $this->option('prompt'),
            'duration' => (int) $this->duration(),
        ];

        // Kling 2.5 Turbo Pro exposes no audio toggle at all; Veo does, and
        // turning it off halves Veo's rate (FR-14). Only send it where the
        // model declares it.
        if ($this->capabilities->supportsNativeAudioToggle) {
            $payload['generate_audio'] = (bool) $this->option('audio');
        } elseif ($this->option('audio')) {
            $this->components->warn(
                $this->capabilities->label.' has no audio toggle; --audio ignored.'
            );
        }

        // Opt-in only: accepted values are unconfirmed for some models, and an
        // unconfirmed parameter is exactly what a first probe should leave out.
        if ($resolution = $this->option('resolution')) {
            $payload['resolution'] = (string) $resolution;
        }

        if ($aspect = $this->option('aspect')) {
            $payload['aspect_ratio'] = (string) $aspect;
        }

        return $payload;
    }

    /**
     * The clip length to probe with: whatever was asked for, else the model's
     * shortest, which is the cheapest question that still gets an answer.
     */
    protected function duration(): float
    {
        $requested = $this->option('duration');

        return $requested === null || $requested === ''
            ? $this->capabilities->minClipLengthSeconds()
            : (float) $requested;
    }

    /**
     * Refuse a duration the model cannot render.
     *
     * Kling accepts 5 or 10 seconds and nothing between, so asking for 7 buys
     * an error rather than a clip — and this command exists precisely to avoid
     * spending money on an avoidable failure.
     */
    protected function validateDuration(): ?string
    {
        $duration = $this->duration();

        if (in_array($duration, $this->capabilities->clipLengths, true)) {
            return null;
        }

        return sprintf(
            '%s renders %s second clips only; %s was requested.',
            $this->capabilities->label,
            implode(' or ', array_map(fn ($l) => rtrim(rtrim(number_format($l, 1), '0'), '.'), $this->capabilities->clipLengths)),
            rtrim(rtrim(number_format($duration, 1), '0'), '.'),
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function call_(string $method, string $url, string $key, ?array $payload = null): ?Response
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Key {$key}"])
                ->acceptJson()
                ->timeout(120)
                ->send($method, $url, $payload === null ? [] : ['json' => $payload]);
        } catch (\Throwable $e) {
            $this->components->error("{$method} {$url} failed: {$e->getMessage()}");

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        $this->components->error("{$method} {$url} returned {$response->status()}.");
        $this->line($this->redact($response->body(), $key));

        if ($response->status() === 404) {
            $this->line('');
            $this->line('A 404 most likely means the URL pattern is wrong rather than the model id.');
            $this->line('Check the model page\'s API tab and set FAL_QUEUE_URL / --model accordingly.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function findRequestId(array $body): ?string
    {
        foreach (['request_id', 'requestId', 'id'] as $field) {
            if (is_string($body[$field] ?? null) && $body[$field] !== '') {
                return $body[$field];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function describeState(array $body): string
    {
        foreach (['status', 'state'] as $field) {
            if (is_string($body[$field] ?? null)) {
                return $body[$field];
            }
        }

        return 'unknown ('.implode(', ', array_keys($body)).')';
    }

    protected function looksTerminal(string $state): bool
    {
        $normalised = strtoupper($state);

        foreach (['COMPLET', 'SUCCE', 'FAIL', 'ERROR', 'CANCEL'] as $needle) {
            if (str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Point at the fields the adapter will need, so the shapes can be read at a
     * glance rather than hunted for in the raw JSON.
     *
     * @param  array<string, mixed>  $result
     */
    protected function summarise(array $result): void
    {
        $this->line('');
        $this->components->info('What the adapter needs from this:');

        $flat = $this->flatten($result);

        $videoUrl = null;
        $cost = null;

        foreach ($flat as $path => $value) {
            if ($videoUrl === null && is_string($value) && preg_match('/\.(mp4|webm|mov)(\?|$)/i', $value)) {
                $videoUrl = $path;
            }

            if ($cost === null && preg_match('/(cost|price|billed|charge)/i', $path)) {
                $cost = "{$path} = ".json_encode($value);
            }
        }

        $this->components->twoColumnDetail('Video URL at', $videoUrl ?? '<fg=yellow>not found — check 04-result.json</>');
        $this->components->twoColumnDetail('Cost reported at', $cost ?? '<fg=yellow>none found — actual_cost_usd stays null and the estimate stands</>');
        $this->line('');
        $this->line("All four files are in {$this->outputDir}");
        $this->line('Send them over and I will write the adapter against them.');
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    protected function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function save(string $filename, array $data): void
    {
        // The captured files are meant to be pasted into a chat, so the key must
        // never be in them.
        $json = $this->redact(
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            (string) config('studio.fal.key'),
        );

        File::put("{$this->outputDir}/{$filename}", $json);
    }

    protected function redact(string $text, string $key): string
    {
        return $key === '' ? $text : str_replace($key, '***REDACTED***', $text);
    }
}
