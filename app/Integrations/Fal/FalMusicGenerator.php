<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\MusicModelCapabilities;
use App\Contracts\Data\MusicRequest;
use App\Contracts\MusicGenerator;
use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use RuntimeException;

/**
 * One background music bed on a fal-hosted music model (FR-13).
 *
 * This is a BED, not a song, and that distinction decides everything here. The
 * assembler mixes it under the narration and ducks it against the narrator's
 * voice (FR-20, sidechaincompress), so the track has one job: be present and
 * stay out of the way. Two consequences follow.
 *
 * NO VOCALS, GUARANTEED. A sung lyric competes with the one voice the whole
 * video is built around (FR-14), and no amount of ducking fixes that — ducking
 * makes it quieter, not less distracting. So a model that can sing is refused
 * unless the registry sends it something that switches singing off. A prompt
 * that asks for "no vocals" is a hope; `instrumental: true` is a guarantee.
 *
 * THE MOOD IS NOT A PROMPT. Scenes carry a mood from a closed enum
 * (SceneMood) — 'tense', 'somber' — and "tense" on its own is a poor text
 * prompt for a music model. Translating it into an instrumentation brief is
 * provider-side phrasing, so it lives here rather than in the enum, next to the
 * fake driver's mood-to-frequency table.
 *
 * Unlike narration, the duration here is NOT the master clock and does not need
 * to be exact — the assembler loops the music input, so a bed shorter than the
 * timeline repeats rather than leaving silence. The duration is still measured
 * from the file so that the "does the existing track still fit?" check in
 * GenerateMusicJob compares a real length rather than a requested one.
 */
class FalMusicGenerator implements MusicGenerator
{
    /**
     * Longer than speech or images: a model rendering minutes of stereo audio
     * is doing more work than one rendering a still. Still bounded — an
     * unbounded wait in a queue worker is how one job holds a worker all day.
     */
    protected const TIMEOUT_SECONDS = 600;

    protected const POLL_SECONDS = 3;

    /**
     * Mood to instrumentation brief.
     *
     * Every entry names instruments and a tempo, says "instrumental", and says
     * the track is a bed under a voice. Text-to-audio models respond to
     * concrete instrumentation far better than to an adjective, and the phrasing
     * is what keeps the result usable under narration rather than merely
     * on-theme.
     *
     * @var array<string, string>
     */
    protected const MOOD_PROMPTS = [
        'neutral' => 'calm instrumental underscore, soft piano and warm pads, steady gentle rhythm, '.
            'low-key and unobtrusive, background bed beneath a spoken voiceover, no vocals',
        'tense' => 'tense instrumental underscore, low sustained strings, muted pulsing percussion, '.
            'rising unease, sparse arrangement, background bed beneath a spoken voiceover, no vocals',
        'joyful' => 'bright uplifting instrumental, acoustic guitar and light marimba, easy mid-tempo groove, '.
            'warm and optimistic, background bed beneath a spoken voiceover, no vocals',
        'somber' => 'slow melancholic instrumental, solo cello and sparse piano, long reverb, '.
            'quiet and reflective, background bed beneath a spoken voiceover, no vocals',
        'wondrous' => 'gentle wondrous instrumental, airy synth pads, soft bells, slow swelling strings, '.
            'spacious and curious, background bed beneath a spoken voiceover, no vocals',
        'triumphant' => 'triumphant instrumental, broad brass and timpani, confident building rhythm, '.
            'cinematic and resolved, background bed beneath a spoken voiceover, no vocals',
    ];

    public function __construct(
        protected FalClient $client,
        protected ModelRegistry $registry,
        protected FfmpegRunner $ffmpeg,
    ) {}

    public function generate(MusicRequest $request): GeneratedMedia
    {
        $model = $this->capabilities();
        $endpoint = $this->endpointFor($model);
        $this->guardVocals($model);

        $requested = $model->clampDuration($request->durationSeconds);
        $payload = $this->buildPayload($request, $model, $requested);

        $mapper = $this->client->mapper();
        $submit = $this->client->submit($endpoint, $payload);
        $requestId = $mapper->requestId($submit);

        if ($requestId === null) {
            throw ProviderException::permanent(
                'fal accepted the music request but returned no request id, so it cannot be collected. '.
                'The work may still be running and billable.',
                'fal',
            );
        }

        $body = $this->awaitResult($endpoint, $requestId, $submit);
        $url = $mapper->audioUrl($body);

        if ($url === null) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported the music was finished but the result carried no audio URL. '.
                'Keys were: '.implode(', ', array_keys($mapper->flatten($body))),
                'fal',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_music_').$this->extensionFor($url);
        $this->client->download($url, $path);

        return new GeneratedMedia(
            path: $path,
            mime: $this->mimeFor($path),
            model: $model->key,

            // Costed on what was REQUESTED, which is also what was clamped and
            // sent. Costing on the measured length would let a model that
            // returns 58 seconds of a 60-second request be recorded as cheaper
            // than it was billed.
            costUsd: $model->costForSeconds($requested),

            // Measured, because GenerateMusicJob decides whether an existing
            // track still covers the timeline by comparing this figure against
            // the timeline. A requested length recorded as a real one would make
            // that check answer a question it was never asked.
            durationSeconds: $this->measure($path),

            meta: [
                'provider_request_id' => $requestId,
                'source_url' => $url,
                'endpoint' => $endpoint,
                'mood' => $request->mood,
                'prompt' => $payload[$model->promptParameter] ?? null,
                'requested_duration_seconds' => $requested,

                // True when the timeline is longer than this model renders in
                // one pass: the assembler will loop the bed to cover the rest.
                // Recorded so an audible repeat has a traceable cause.
                'looped_to_cover_timeline' => $model->loopsToCover($request->durationSeconds),

                // fal echoes the seed it used when none was supplied. Recorded
                // so a bed the owner liked can be regenerated (NFR-5).
                'seed' => $this->seedFrom($body),

                'actual_cost_usd' => $mapper->actualCostUsd($body),
            ],
        );
    }

    public function costForSeconds(float $durationSeconds): float
    {
        return $this->capabilities()->costForSeconds(max(0.0, $durationSeconds));
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function capabilities(): MusicModelCapabilities
    {
        return $this->registry->defaultMusic();
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
                    "fal reported the music request {$requestId} did not complete.",
                    'fal',
                );
            }

            sleep(self::POLL_SECONDS);
        }

        throw ProviderException::because(
            ProviderFailureReason::Timeout,
            sprintf(
                'fal music request %s did not finish within %ds. It may still be running and billable.',
                $requestId,
                self::TIMEOUT_SECONDS,
            ),
            'fal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(MusicRequest $request, MusicModelCapabilities $model, float $seconds): array
    {
        // The registry's literal extras go on FIRST, so the prompt and duration
        // below win if a config entry names the same field. A payload default
        // that silently overwrote the length would buy a bed of the wrong size.
        $payload = $model->payloadDefaults;

        $payload[$model->promptParameter] = $this->promptFor($request->mood);

        // Null means this model takes no length at all and returns whatever it
        // returns. The assembler loops and trims, so that is a usable bed —
        // sending an invented field name would just 422.
        if ($model->durationParameter !== null) {
            $payload[$model->durationParameter] = $seconds;
        }

        return $payload;
    }

    protected function promptFor(string $mood): string
    {
        return self::MOOD_PROMPTS[mb_strtolower(trim($mood))] ?? self::MOOD_PROMPTS['neutral'];
    }

    /**
     * Refuse, before spending anything, to call a singing model that has not
     * been told to stop.
     *
     * InvalidRequest rather than ProviderError: nothing is wrong at fal, the
     * registry entry is wrong here. Retrying would buy the same unusable track
     * again — and "unusable" is the point, because a vocal line over the
     * narration is not a degraded video, it is a ruined one.
     */
    protected function guardVocals(MusicModelCapabilities $model): void
    {
        if ($model->suppressesVocals()) {
            return;
        }

        throw ProviderException::because(
            ProviderFailureReason::InvalidRequest,
            sprintf(
                '%s can generate vocals and nothing in its registry entry switches them off. '.
                'Music here is a bed under narration (FR-13, FR-20), and a sung line competes with '.
                'the narrator rather than supporting them. Add instrumental (or lyrics) to this '.
                "model's payload_defaults in config/studio.php, or choose an instrumental model.",
                $model->label,
            ),
            'fal',
        );
    }

    protected function endpointFor(MusicModelCapabilities $model): string
    {
        $endpoint = trim((string) $model->endpoint);

        if ($endpoint === '') {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Music model '{$model->key}' declares no fal endpoint. ".
                'Add one to config/studio.php music_models, or bind a different driver.',
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
            // Permanent: ffprobe fails the same way on a retry, and the track
            // has already been paid for. Without a length the "still fits?"
            // check cannot run, so the next press of the audio button would buy
            // another one.
            throw ProviderException::permanent(
                "The music downloaded but its duration could not be measured: {$e->getMessage()}",
                'fal',
                $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function seedFrom(array $body): ?int
    {
        $seed = $body['seed'] ?? null;

        return is_int($seed) || (is_string($seed) && ctype_digit($seed)) ? (int) $seed : null;
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
