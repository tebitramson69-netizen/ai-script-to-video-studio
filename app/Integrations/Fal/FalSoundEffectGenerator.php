<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SoundEffectModelCapabilities;
use App\Contracts\Data\SoundEffectRequest;
use App\Contracts\ProviderException;
use App\Contracts\SoundEffectGenerator;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use RuntimeException;

/**
 * One ambience effect per scene on a fal-hosted sound-effect model (FR-13).
 *
 * The third audio adapter, and the one with the tightest constraint. Narration
 * is the master clock, music is one bed for the whole video — an effect is
 * neither: it belongs to ONE scene, it has to be positioned at that scene's
 * offset in the finished timeline, and it has to survive being far shorter than
 * the scene it covers.
 *
 * That last point is the whole design. The default model generates at most 22
 * seconds; scenes routinely run longer. So the assembler loops the effect, and
 * this adapter asks the model to generate a LOOPING effect whenever it knows the
 * loop is coming. An effect looped from a clip that was not generated to loop
 * has a seam every 22 seconds, and the seam lands under the narration.
 *
 * What this deliberately does NOT do is invent a cue. The request's description
 * comes from a closed keyword map over the scene's own prose, and a scene with no
 * match never reaches this class. At $0.0194 an effect, the wasteful failure is
 * not an expensive model — it is six confident effects that do not belong in the
 * video.
 */
class FalSoundEffectGenerator implements SoundEffectGenerator
{
    /** Effects are the shortest audio in the pipeline, so the wait is short too. */
    protected const TIMEOUT_SECONDS = 180;

    protected const POLL_SECONDS = 2;

    public function __construct(
        protected FalClient $client,
        protected ModelRegistry $registry,
        protected FfmpegRunner $ffmpeg,
    ) {}

    public function generate(SoundEffectRequest $request): GeneratedMedia
    {
        $description = trim($request->description);

        if ($description === '') {
            // An empty cue would become whatever the model imagines, and it
            // would be paid for. Scenes with no cue are meant to be skipped
            // before they get here; this is the guard for when they are not.
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                'Refusing to generate a sound effect from an empty description.',
                'fal',
            );
        }

        $model = $this->capabilities();
        $endpoint = $this->endpointFor($model);

        $requested = $model->clampDuration($request->durationSeconds);
        $willLoop = $model->loopsToCover($request->durationSeconds);
        $payload = $this->buildPayload($description, $model, $requested, $willLoop);

        $mapper = $this->client->mapper();
        $submit = $this->client->submit($endpoint, $payload);
        $requestId = $mapper->requestId($submit);

        if ($requestId === null) {
            throw ProviderException::permanent(
                'fal accepted the sound effect request but returned no request id, so it cannot be '.
                'collected. The work may still be running and billable.',
                'fal',
            );
        }

        $body = $this->awaitResult($endpoint, $requestId, $submit);
        $url = $mapper->audioUrl($body);

        if ($url === null) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported the sound effect was finished but the result carried no audio URL. '.
                'Keys were: '.implode(', ', array_keys($mapper->flatten($body))),
                'fal',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_sfx_').$this->extensionFor($url);
        $this->client->download($url, $path);

        return new GeneratedMedia(
            path: $path,
            mime: $this->mimeFor($path),
            model: $model->key,

            // Flat per effect, so the length does not enter into it. The budget
            // question for SFX is how many scenes carry a cue.
            costUsd: $model->costPerEffectUsd,

            // Measured. The assembler needs the real length to decide how many
            // times an effect repeats across its scene, and a requested length
            // recorded as a real one would make it loop the wrong number of
            // times — audible as a gap or an overlap at the scene boundary.
            durationSeconds: $this->measure($path),

            meta: [
                'provider_request_id' => $requestId,
                'source_url' => $url,
                'endpoint' => $endpoint,
                'description' => $description,
                'requested_duration_seconds' => $requested,

                // Whether a repeat is acceptable depends on the sound: looped
                // rain is invisible, a looped door slam is a woodpecker. Recorded
                // so the judgement can be made after listening rather than
                // guessed at beforehand.
                'looped_to_cover_scene' => $willLoop,
                'generated_as_loop' => $payload['loop'] ?? false,

                'actual_cost_usd' => $mapper->actualCostUsd($body),
            ],
        );
    }

    public function costPerEffectUsd(): float
    {
        return $this->capabilities()->costPerEffectUsd;
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function capabilities(): SoundEffectModelCapabilities
    {
        return $this->registry->defaultSoundEffect();
    }

    /**
     * @param  array<string, mixed>  $submit
     * @return array<string, mixed>
     */
    protected function awaitResult(string $endpoint, string $requestId, array $submit): array
    {
        $mapper = $this->client->mapper();
        $statusUrl = $mapper->statusUrl($submit);
        $resultUrl = $mapper->resultUrl($submit);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $status = $mapper->status($this->client->status($endpoint, $requestId, $statusUrl));

            if ($status === ProviderRequestStatus::Completed) {
                return $this->client->result($endpoint, $requestId, $resultUrl);
            }

            if ($status === ProviderRequestStatus::Failed || $status === ProviderRequestStatus::Cancelled) {
                throw ProviderException::because(
                    ProviderFailureReason::ProviderError,
                    "fal reported the sound effect request {$requestId} did not complete.",
                    'fal',
                );
            }

            sleep(self::POLL_SECONDS);
        }

        throw ProviderException::because(
            ProviderFailureReason::Timeout,
            sprintf(
                'fal sound effect request %s did not finish within %ds. It may still be running and billable.',
                $requestId,
                self::TIMEOUT_SECONDS,
            ),
            'fal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(
        string $description,
        SoundEffectModelCapabilities $model,
        float $seconds,
        bool $willLoop,
    ): array {
        // Registry extras first, so the fields below win if a config entry names
        // the same one. A payload default that overwrote the length or the
        // description would buy the wrong sound at the wrong size.
        $payload = $model->payloadDefaults;

        $payload[$model->promptParameter] = $description;

        if ($model->durationParameter !== null) {
            $payload[$model->durationParameter] = $seconds;
        }

        // Asked for only when the effect is actually going to repeat. Sent
        // unconditionally it would make a one-shot that happens to fit its scene
        // loopable for no reason, which constrains the sound the model produces
        // without buying anything.
        if ($willLoop && $model->supportsLoop) {
            $payload['loop'] = true;
        }

        return $payload;
    }

    protected function endpointFor(SoundEffectModelCapabilities $model): string
    {
        $endpoint = trim((string) $model->endpoint);

        if ($endpoint === '') {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Sound effect model '{$model->key}' declares no fal endpoint. ".
                'Add one to config/studio.php sfx_models, or bind a different driver.',
                'fal',
            );
        }

        return $endpoint;
    }

    protected function measure(string $path): float
    {
        try {
            return $this->ffmpeg->durationSeconds($path);
        } catch (RuntimeException $e) {
            throw ProviderException::permanent(
                "The sound effect downloaded but its duration could not be measured: {$e->getMessage()}",
                'fal',
                $e,
            );
        }
    }

    protected function extensionFor(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'opus', 'flac'], true)
            ? ".{$extension}"
            : '.mp3';
    }

    protected function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'wav' => 'audio/wav',
            'm4a', 'aac' => 'audio/aac',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }
}
