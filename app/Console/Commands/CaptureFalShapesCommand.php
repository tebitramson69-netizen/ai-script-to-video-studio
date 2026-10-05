<?php

namespace App\Console\Commands;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ProviderException;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalPayloadBuilder;
use App\Integrations\Fal\FalResponseMapper;
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
 *
 * The payload is built by FalPayloadBuilder, the same class the adapter uses.
 * That is the whole value of the probe: a capture that sent a narrower payload
 * than the adapter would declare the endpoint good and still leave the adapter
 * able to 4xx on the first real render — having already spent the money that
 * was meant to rule that out.
 *
 * --minimal opts out, sending prompt and duration alone. That is the bisect
 * tool, not the default: when the full payload comes back 4xx, it answers
 * whether the fault is an optional parameter or the endpoint itself.
 */
class CaptureFalShapesCommand extends Command
{
    protected $signature = 'studio:capture-fal-shapes
                            {--model=kling-2-5-turbo-pro : Registry key from config/studio.php video_models}
                            {--endpoint= : Raw provider model id, overriding the registry}
                            {--prompt=A calm river at dawn, slow drifting mist : Prompt for the test clip}
                            {--duration= : Clip length in seconds. Defaults to the model minimum, which is the cheapest probe.}
                            {--resolution= : Override the resolution. Sent only if the model declares it in payload_parameters.}
                            {--aspect= : Override the aspect ratio. Sent only if the model declares it in payload_parameters.}
                            {--audio : Ask for native audio. Ignored by models with no audio toggle.}
                            {--image= : Starting frame for an image-to-video model. Required for a real run on one.}
                            {--minimal : Probe with prompt and duration alone, bypassing the adapter\'s payload. Use to bisect a 4xx.}
                            {--poll-interval=5 : Seconds between status checks}
                            {--max-polls=60 : Give up after this many checks}
                            {--collect= : Finish a run that already submitted. Captures status and result for an existing request id without generating anything, so a half-finished capture costs nothing to complete.}
                            {--dry-run : Print the request and exit without calling anything}
                            {--out= : Directory for the captured JSON}';

    protected $description = 'Capture fal submit/status/result payload shapes from one small real generation';

    protected string $outputDir;

    protected ModelCapabilities $capabilities;

    protected int $rejections = 0;

    /**
     * Status of the most recent non-2xx, so firstThatAnswers() can tell a wrong
     * URL shape from a real refusal without call_() having to return both a
     * response and a reason.
     */
    protected ?int $lastStatus = null;

    /**
     * Kept in step with FalClient::SHAPE_MISMATCH_STATUSES deliberately: if the
     * probe and the adapter disagree about what "wrong URL" looks like, the
     * probe stops predicting the adapter, which is its only purpose.
     */
    protected const SHAPE_MISMATCH_STATUSES = [404, 405];

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

        $this->outputDir = $this->option('out')
            ?: storage_path('app/private/fal-capture/'.now()->format('Ymd-His'));

        // Collecting an existing request generates nothing, so it skips the
        // payload build, the estimate and the confirmation entirely. It is
        // placed before all of them deliberately: a run that died after
        // submitting has already been billed, and the way to finish it must not
        // route through the code that spends.
        $collecting = trim((string) $this->option('collect'));

        if ($collecting !== '') {
            if ($key === '') {
                $this->components->error('FAL_KEY is not set. Put it in .env — never on the command line, where it lands in your shell history.');

                return self::FAILURE;
            }

            $this->components->info("Collecting {$collecting} — nothing is generated and nothing is charged.");

            return $this->collectShapes($model, $key, $collecting, $this->priorSubmitBody());
        }

        try {
            $payload = $this->buildPayload();
        } catch (ProviderException $e) {
            // The builder refuses an impossible request — a duration the model
            // cannot render, an unsupported mode — before anything is sent.
            // Failing here is the point: the alternative is paying to find out.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $submitUrl = "{$base}/{$model}";

        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Submit URL</>', $submitUrl);
        $this->components->twoColumnDetail(
            '<fg=cyan>Payload</>',
            json_encode($this->abbreviate($payload), JSON_UNESCAPED_SLASHES),
        );
        $this->components->twoColumnDetail(
            '<fg=cyan>Built by</>',
            $this->option('minimal')
                ? '--minimal (prompt + duration only; NOT what the adapter sends)'
                : 'FalPayloadBuilder — byte for byte what the adapter will send',
        );
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

        return $this->collectShapes($model, $key, $requestId, $submit->json() ?? []);
    }

    /**
     * Capture status and result for a request that is already running.
     *
     * Shared by the fresh run and by --collect, because a submitted request is
     * a submitted request: fal has accepted the work and will bill it either
     * way, and the code that reads the shapes back must not differ depending on
     * which command invocation paid for them.
     *
     * @param  array<string, mixed>  $submitBody
     */
    protected function collectShapes(string $model, string $key, string $requestId, array $submitBody): int
    {
        // ---- 2. Status -----------------------------------------------------
        // Resolved through the same authority the adapter uses, not built by
        // hand. The first version of this command constructed
        // {queue}/{model}/requests/{id}/status directly and got a 405 from the
        // live provider - which looked like a broken adapter when the adapter
        // would have followed fal's own status_url and worked. A probe that can
        // fail where the thing it validates succeeds is worse than no probe.
        $statusCandidates = $this->statusCandidates($submitBody, $model, $requestId);

        $this->components->twoColumnDetail(
            'Status URL',
            $statusCandidates[0].(count($statusCandidates) > 1 ? ' (+'.(count($statusCandidates) - 1).' fallback)' : ''),
        );

        $statusUrl = null;
        $capturedInProgress = false;
        $terminal = null;

        for ($poll = 1; $poll <= (int) $this->option('max-polls'); $poll++) {
            // Only the first poll walks the candidates; once one answers, that
            // is the URL for the rest of this request's life.
            $status = $statusUrl === null
                ? $this->firstThatAnswers($statusCandidates, $key, $statusUrl)
                : $this->call_('GET', $statusUrl, $key);

            if ($status === null) {
                return self::FAILURE;
            }

            $body = $status->json() ?? [];
            $state = $this->describeState($body);

            $this->line("  poll {$poll}: {$state}");

            if ($this->looksTerminal($state)) {
                $terminal = $body;
                break;
            }

            // Keep the first mid-flight response: the in-progress strings are
            // exactly what checkStatus() has to recognise, and they vanish once
            // the job finishes.
            //
            // Saved only AFTER the terminal check. Writing it first meant a
            // --collect of an already-finished request put a COMPLETED body in
            // a file named in-progress - harmless as output, but these files
            // become test fixtures, and that one would have taught the suite
            // that a running request looks finished.
            if (! $capturedInProgress) {
                $this->save('02-status-in-progress.json', $body);
                $capturedInProgress = true;
            }

            sleep(max(1, (int) $this->option('poll-interval')));
        }

        if ($terminal === null) {
            $this->components->error(
                'Never reached a terminal status.'.
                ($capturedInProgress
                    ? ' 02-status-in-progress.json still has a sample.'
                    : ' No status response was captured.')
            );

            return self::FAILURE;
        }

        $this->save('03-status-complete.json', $terminal);

        // Names only what was written. A request that was already finished has
        // no mid-flight sample to offer, and claiming a file that is not there
        // sends you looking for it.
        $this->components->info($capturedInProgress
            ? 'Status captured → 02-status-in-progress.json, 03-status-complete.json'
            : 'Status captured → 03-status-complete.json');

        if (! $capturedInProgress) {
            // Kept off the info() line: that component wraps at terminal width,
            // which splits the sentence and makes it unmatchable.
            $this->line('  Already finished, so no mid-flight sample was available.');
        }

        // ---- 3. Result -----------------------------------------------------
        $resolved = null;
        $result = $this->firstThatAnswers(
            $this->resultCandidates($submitBody, $model, $requestId),
            $key,
            $resolved,
        );

        if ($result === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Result URL', (string) $resolved);

        $this->save('04-result.json', $result->json() ?? ['raw' => $result->body()]);
        $this->components->info('Result captured → 04-result.json');

        $this->summarise($result->json() ?? []);

        return self::SUCCESS;
    }

    /**
     * The submit body an earlier run saved, when --out points at its directory.
     *
     * Worth reading rather than ignoring: it carries fal's own status_url and
     * response_url, which are authoritative about its routing. Without it the
     * URLs are reconstructed, which works but exercises the guess instead of
     * the fact. Absent or unreadable is not an error — reconstruction is the
     * documented fallback.
     *
     * @return array<string, mixed>
     */
    protected function priorSubmitBody(): array
    {
        $path = "{$this->outputDir}/01-submit.json";

        if (! is_file($path)) {
            $this->components->warn(
                'No 01-submit.json in the output directory, so the request URLs are '.
                'reconstructed rather than taken from fal. Pass --out=<the earlier '.
                'capture directory> to use the ones fal gave.'
            );

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->components->warn("{$path} is not readable JSON; reconstructing the URLs instead.");

            return [];
        }

        $this->components->info('Reusing the status and result URLs fal returned in 01-submit.json.');

        return $decoded;
    }

    /**
     * The exact body the adapter would send for this model.
     *
     * Routed through FalPayloadBuilder rather than assembled here, so there is
     * one place where a fal request is shaped. The previous version of this
     * command built its own narrower payload, which meant a successful capture
     * proved only that the *probe* worked — the adapter could still 4xx on the
     * first real render over a parameter the probe never sent, after the money
     * meant to rule that out had already been spent.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException when the model cannot render what was asked for
     */
    protected function buildPayload(): array
    {
        if ($this->option('minimal')) {
            // The bisect path: the narrowest request that can still answer
            // "does this endpoint exist and what does it return?". Use it when
            // the full payload comes back 4xx, to tell an unaccepted optional
            // parameter apart from a wrong URL.
            return [
                'prompt' => (string) $this->option('prompt'),
                'duration' => (int) $this->duration(),
            ];
        }

        $this->warnAboutIgnoredOptions();

        return app(FalPayloadBuilder::class)->build($this->clipRequest(), $this->capabilities);
    }

    /**
     * Say so when a flag will not reach the wire.
     *
     * A flag that is silently dropped is worse than one that is refused: you
     * read the captured payload, see the parameter missing, and conclude the
     * model rejected it — when in fact it was never sent. That is a false
     * finding bought with a real charge.
     */
    protected function warnAboutIgnoredOptions(): void
    {
        if ($this->option('audio') && ! $this->capabilities->supportsNativeAudioToggle) {
            $this->components->warn(
                $this->capabilities->label.' has no audio toggle; --audio is ignored.'
            );
        }

        foreach (['resolution', 'aspect'] as $option) {
            $parameter = $option === 'aspect' ? 'aspect_ratio' : $option;

            if ($this->option($option) && ! $this->capabilities->sendsParameter($parameter)) {
                $this->components->warn(sprintf(
                    '%s does not declare %s in payload_parameters, so --%s is ignored. '.
                    'Add it to config/studio.php once the model page confirms the name.',
                    $this->capabilities->label,
                    $parameter,
                    $option,
                ));
            }
        }
    }

    /**
     * The probe request, built from the model's own declared defaults so that
     * what is captured is representative rather than arbitrary.
     */
    protected function clipRequest(): ClipRequest
    {
        // An image-to-video-only model cannot be probed with a text-to-video
        // request, and that is exactly the model whose shapes are least
        // certain — so the probe has to be able to reach it.
        $needsImage = ! $this->capabilities->supportsMode(GenerationMode::TextToVideo);

        return new ClipRequest(
            prompt: (string) $this->option('prompt'),
            durationSeconds: $this->duration(),
            aspectRatio: $this->aspectRatio(),
            mode: $needsImage ? GenerationMode::ImageToVideo : GenerationMode::TextToVideo,
            resolution: $this->resolution(),
            referenceImagePath: $needsImage ? $this->referenceImage() : null,

            // FR-14 inverted: every narrated project mutes native audio, so the
            // probe must too unless --audio is passed, or it captures a shape
            // the pipeline will never ask for — at double the rate on Veo.
            muteNativeAudio: ! $this->option('audio'),
            modelKey: $this->capabilities->key,
        );
    }

    /**
     * The starting frame for an image-to-video probe.
     *
     * A real run needs a real image — the point is to learn what the endpoint
     * accepts, and a 1x1 pixel may well be rejected for reasons that tell you
     * nothing. A dry run gets a synthesised placeholder instead, so the payload
     * shape can still be inspected without one.
     */
    protected function referenceImage(): string
    {
        $supplied = trim((string) $this->option('image'));

        if ($supplied !== '') {
            if (! is_file($supplied)) {
                throw ProviderException::permanent("Reference image {$supplied} does not exist.", 'fal');
            }

            return $supplied;
        }

        if (! $this->option('dry-run')) {
            throw ProviderException::permanent(
                $this->capabilities->label.' is image-to-video only, so it needs a starting frame. '.
                'Pass --image=/path/to/reference.png — ideally a real character reference at the '.
                'project aspect ratio, since that is what the pipeline will send.',
                'fal',
            );
        }

        $this->components->warn(
            'No --image given, so the dry run uses a 1x1 placeholder. A real run needs a real one.'
        );

        $path = tempnam(sys_get_temp_dir(), 'fal_probe_').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return $path;
    }

    protected function aspectRatio(): AspectRatio
    {
        $requested = (string) ($this->option('aspect') ?? '');

        if ($requested === '') {
            return $this->capabilities->aspectRatios[0];
        }

        $ratio = AspectRatio::tryFrom($requested);

        if ($ratio === null || ! $this->capabilities->supportsAspectRatio($ratio)) {
            throw ProviderException::permanent(
                sprintf(
                    '%s does not declare aspect ratio %s. Declared: %s.',
                    $this->capabilities->label,
                    $requested,
                    implode(', ', array_map(fn (AspectRatio $a) => $a->value, $this->capabilities->aspectRatios)),
                ),
                'fal',
            );
        }

        return $ratio;
    }

    protected function resolution(): VideoResolution
    {
        $requested = (string) ($this->option('resolution') ?? '');

        if ($requested === '') {
            return $this->capabilities->defaultResolution;
        }

        $resolution = VideoResolution::tryFrom($requested);

        if ($resolution === null || ! $this->capabilities->supportsResolution($resolution)) {
            throw ProviderException::permanent(
                sprintf(
                    '%s does not declare resolution %s. Declared: %s.',
                    $this->capabilities->label,
                    $requested,
                    implode(', ', array_map(fn (VideoResolution $r) => $r->value, $this->capabilities->resolutions)),
                ),
                'fal',
            );
        }

        return $resolution;
    }

    /**
     * The payload with any data URI shortened.
     *
     * An inlined reference image is hundreds of kilobytes of base64; printing
     * it verbatim would bury the three fields actually worth reading. The file
     * written to disk keeps the whole thing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function abbreviate(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'data:')) {
                $payload[$key] = mb_substr($value, 0, 40).'…['.number_format(mb_strlen($value)).' chars]';
            }
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
     * Where to ask for this request's status, best source first.
     *
     * fal returns a status_url in the submit body and is authoritative about
     * its own routing, so that is always preferred. FalClient::requestUrls()
     * supplies the fallbacks, which keeps one implementation of the awkward
     * part: a five-segment model id addresses its requests under only the first
     * two segments, and this command must not re-guess that independently of
     * the adapter.
     *
     * @param  array<string, mixed>  $submitBody
     * @return list<string>
     */
    protected function statusCandidates(array $submitBody, string $model, string $requestId): array
    {
        $fromProvider = $this->mapper()->statusUrl($submitBody);

        return $this->dedupe([
            ...($fromProvider === null ? [] : [$fromProvider]),
            ...array_map(
                fn (string $base) => $base.'/status',
                $this->client()->requestUrls($model, $requestId),
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $submitBody
     * @return list<string>
     */
    protected function resultCandidates(array $submitBody, string $model, string $requestId): array
    {
        $fromProvider = $this->mapper()->resultUrl($submitBody);
        $bare = $this->client()->requestUrls($model, $requestId);

        // Mirrors FalClient::result(): sources disagree on whether the output
        // sits at the bare request URL or under /response, so both are tried.
        return $this->dedupe([
            ...($fromProvider === null ? [] : [$fromProvider]),
            ...$bare,
            ...array_map(fn (string $url) => $url.'/response', $bare),
        ]);
    }

    /**
     * The first candidate that is not a shape mismatch.
     *
     * Falls through on the statuses FalClient treats the same way, so the probe
     * and the adapter agree about what "wrong URL" looks like. Anything else -
     * a 401, a 422 - is answered identically by every candidate, so it is
     * reported rather than retried.
     *
     * @param  list<string>  $urls
     */
    protected function firstThatAnswers(array $urls, string $key, ?string &$resolved): ?Response
    {
        foreach ($urls as $index => $url) {
            $response = $this->call_('GET', $url, $key, quiet: $index < count($urls) - 1);

            if ($response !== null) {
                $resolved = $url;

                return $response;
            }

            if (! in_array($this->lastStatus, self::SHAPE_MISMATCH_STATUSES, true)) {
                return null;
            }

            if ($index < count($urls) - 1) {
                $this->line("  {$url} -> {$this->lastStatus}, trying the next shape");
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    protected function dedupe(array $urls): array
    {
        return array_values(array_unique(array_filter($urls, fn (string $url) => trim($url) !== '')));
    }

    protected function client(): FalClient
    {
        return app(FalClient::class);
    }

    protected function mapper(): FalResponseMapper
    {
        return $this->client()->mapper();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function call_(string $method, string $url, string $key, ?array $payload = null, bool $quiet = false): ?Response
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Key {$key}"])
                ->acceptJson()
                ->timeout(120)
                ->send($method, $url, $payload === null ? [] : ['json' => $payload]);
        } catch (\Throwable $e) {
            $this->lastStatus = null;
            $this->components->error("{$method} {$url} failed: {$e->getMessage()}");

            return null;
        }

        if ($response->successful()) {
            $this->lastStatus = null;

            return $response;
        }

        $this->lastStatus = $response->status();

        // A candidate URL that is merely the wrong shape is not news: the caller
        // is walking a list and will say so in one line. Reporting each as an
        // ERROR would bury the one failure that matters.
        if ($quiet && in_array($response->status(), self::SHAPE_MISMATCH_STATUSES, true)) {
            return null;
        }

        $this->components->error("{$method} {$url} returned {$response->status()}.");
        $this->line($this->redact($this->pretty($response->body()), $key));

        // A rejection is the most valuable thing this command can bring back
        // and the cheapest: fal bills for successful outputs, so a 4xx costs
        // nothing but names the field and the type it wanted. Printing it to
        // the terminal is not keeping it — scrollback is lost, and the probe
        // is a one-shot. Write it next to the request that caused it.
        $this->save(
            sprintf('99-rejected-%02d-%s-%d.json', ++$this->rejections, strtolower($method), $response->status()),
            [
                'method' => $method,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'headers' => $response->headers(),
            ],
        );

        if (in_array($response->status(), self::SHAPE_MISMATCH_STATUSES, true)) {
            $this->line('');
            $this->line('Every candidate URL was refused, so the pattern is wrong rather than the model id.');
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

        // Owns the directory rather than trusting handle() to have made it:
        // the rejection files are written from call_(), which can now run on
        // paths that never reached the pre-submit ensureDirectoryExists().
        File::ensureDirectoryExists($this->outputDir);
        File::put("{$this->outputDir}/{$filename}", $json);
    }

    protected function redact(string $text, string $key): string
    {
        return $key === '' ? $text : str_replace($key, '***REDACTED***', $text);
    }

    /**
     * Re-indent a JSON error body so it can be read on screen.
     *
     * fal returns validation errors as a nested `detail` array on one line.
     * That line is the answer to "which field, and what type did it want?" —
     * worth the few characters it takes to make it legible. Anything that is
     * not JSON is passed through untouched.
     */
    protected function pretty(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $body;
    }
}
