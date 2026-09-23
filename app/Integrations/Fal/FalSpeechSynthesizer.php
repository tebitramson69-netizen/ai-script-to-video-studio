<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SpeechModelCapabilities;
use App\Contracts\Data\SpeechRequest;
use App\Contracts\ProviderException;
use App\Contracts\SpeechSynthesizer;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use RuntimeException;

/**
 * Narration on any fal-hosted text-to-speech model (FR-12).
 *
 * This is the most load-bearing audio in the system and the cheapest. Narration
 * is the master clock (FR-16/17/18): shot durations are rounded up to it, the
 * assembler trims or holds every clip to it, and the export's runtime is it. A
 * 60-second video needs about 900 characters of narration — under $0.02 on
 * Kokoro against roughly $4.48 for the video — so the model is chosen for voice
 * and language, never for price.
 *
 * Which model is a config decision (`studio.default_speech_model`), read from
 * the registry exactly as the video adapter reads its own. Language may change
 * the ENDPOINT rather than a parameter: Kokoro ships one model id per language.
 *
 * The rule that matters most is at the bottom of synthesize(): the duration is
 * MEASURED from the returned file, never taken from the provider's own report.
 * A reported duration that is off by a tenth of a second desynchronises every
 * shot after it, and the error accumulates down the timeline.
 */
class FalSpeechSynthesizer implements SpeechSynthesizer
{
    /**
     * Speech is quick — seconds, not minutes — so this waits inline rather than
     * splitting into submit/collect the way video does. Bounded anyway: an
     * unbounded wait in a queue worker is how one job holds a worker for an
     * hour.
     */
    protected const TIMEOUT_SECONDS = 180;

    protected const POLL_SECONDS = 2;

    public function __construct(
        protected FalClient $client,
        protected ModelRegistry $registry,
        protected FfmpegRunner $ffmpeg,
    ) {}

    public function synthesize(SpeechRequest $request): GeneratedMedia
    {
        $text = trim($request->text);

        if ($text === '') {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                'Refusing to synthesise empty narration.',
                'fal',
            );
        }

        $model = $this->capabilities();
        $endpoint = $this->endpointFor($model, $request->language);
        $payload = $this->buildPayload($request, $text, $model);

        $mapper = $this->client->mapper();
        $submit = $this->client->submit($endpoint, $payload);
        $requestId = $mapper->requestId($submit);

        if ($requestId === null) {
            throw ProviderException::permanent(
                'fal accepted the narration but returned no request id, so it cannot be collected. '.
                'The work may still be running and billable.',
                'fal',
            );
        }

        $body = $this->awaitResult($endpoint, $requestId, $submit);
        $url = $mapper->audioUrl($body);

        if ($url === null) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported the narration was finished but the result carried no audio URL. '.
                'Keys were: '.implode(', ', array_keys($mapper->flatten($body))),
                'fal',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_vo_').$this->extensionFor($url);
        $this->client->download($url, $path);

        return new GeneratedMedia(
            path: $path,
            mime: $this->mimeFor($path),
            model: $model->key,
            costUsd: $model->costForCharacters(mb_strlen($text)),

            // MEASURED, not reported. Narration is the master clock, and a
            // provider's own figure being a tenth of a second out
            // desynchronises every shot after it — an error that accumulates
            // down the timeline rather than staying local.
            durationSeconds: $this->measure($path),

            meta: [
                'provider_request_id' => $requestId,
                'source_url' => $url,
                'endpoint' => $endpoint,
                'language' => $request->language,
                'characters' => mb_strlen($text),
                'voice_id' => $request->voiceId ?? $model->defaultVoice,
                'reported_duration_seconds' => $mapper->durationSeconds($body),
                'actual_cost_usd' => $mapper->actualCostUsd($body),
            ],
        );
    }

    public function costPer1kCharactersUsd(): float
    {
        return $this->capabilities()->costPer1kCharactersUsd;
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function capabilities(): SpeechModelCapabilities
    {
        return $this->registry->defaultSpeech();
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
                    "fal reported the narration request {$requestId} did not complete.",
                    'fal',
                );
            }

            sleep(self::POLL_SECONDS);
        }

        throw ProviderException::because(
            ProviderFailureReason::Timeout,
            sprintf(
                'fal narration request %s did not finish within %ds. It may still be running and billable.',
                $requestId,
                self::TIMEOUT_SECONDS,
            ),
            'fal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(SpeechRequest $request, string $text, SpeechModelCapabilities $model): array
    {
        $payload = ['text' => $text];

        // Opt-in, for the same reason as the video adapter: the voice
        // parameter's name and accepted values are unconfirmed on these models,
        // and one unaccepted parameter fails the whole call. Without it the
        // model uses its own default voice, which is a usable narration rather
        // than an error.
        $voice = $request->voiceId ?: $model->defaultVoice;

        if ($voice !== null && $model->sendsParameter('voice')) {
            $payload['voice'] = $voice;
        }

        return $payload;
    }

    protected function endpointFor(SpeechModelCapabilities $model, string $language): string
    {
        try {
            return $model->endpointFor($language);
        } catch (\InvalidArgumentException $e) {
            // A model that cannot speak the project's language is a
            // configuration error, not a transient one. Narration in the wrong
            // language is a wrong result, not a degraded one — and it would be
            // paid for before anyone noticed.
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                $e->getMessage(),
                'fal',
                $e,
            );
        }
    }

    protected function measure(string $path): float
    {
        try {
            return $this->ffmpeg->durationSeconds($path);
        } catch (RuntimeException $e) {
            // Without a duration the file is useless to the timeline, so this
            // fails rather than guessing. Permanent: ffprobe will fail the same
            // way on a retry, and the audio has already been paid for.
            throw ProviderException::permanent(
                "The narration downloaded but its duration could not be measured: {$e->getMessage()}",
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
