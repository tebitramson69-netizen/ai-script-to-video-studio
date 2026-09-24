<?php

namespace App\Contracts\Data;

/**
 * Everything the pipeline needs to know about one music model.
 *
 * The fourth sibling of ModelCapabilities, SpeechModelCapabilities and
 * ImageModelCapabilities, and kept apart from them for the same reason: the
 * constraints do not overlap. A video model is priced per second against a clip
 * ladder, a speech model per character against a language, an image model per
 * image against a size preset. A music model is priced per second OR per
 * request, capped by a maximum length, and — uniquely — is capable of producing
 * something actively harmful: a sung vocal line over the narration.
 *
 * Three fields carry most of the weight.
 *
 * `costPerRequestUsd` and `costPerSecondUsd` exist together because providers
 * bill music both ways and the difference is large. ACE-Step charges $0.0002 a
 * second; Stable Audio 3 Medium charges a flat $0.0417 however long the track
 * is. Total = flat + per-second x seconds, so one formula covers both and a
 * model that bills only one way leaves the other at zero.
 *
 * `promptParameter` and `durationParameter` are field NAMES, not values,
 * because audio models disagree about them. ACE-Step's style input is `tags`
 * and its length is `duration`; Stable Audio Open's length is `seconds_total`.
 * A hard-coded name would 422 on half the registry, and a 422 from fal reads
 * like a wrong URL — the most expensive kind of wrong, because it sends you
 * looking in the wrong place.
 *
 * `generatesVocals` is the safety flag. Music here is a BED under narration
 * (FR-13, FR-20): the mix ducks it against the narrator's voice, and a sung
 * lyric competes with the one voice the video is built around. A model that can
 * sing must be told not to, and the adapter refuses to call one that has no
 * suppression configured rather than pay for a track it cannot use.
 */
readonly class MusicModelCapabilities
{
    /**
     * @param  array<string, mixed>  $payloadDefaults  literal fields always sent to this model
     * @param  list<string>  $payloadParameters  optional fields this model is known to accept
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $endpoint,
        public float $costPerRequestUsd = 0.0,
        public float $costPerSecondUsd = 0.0,
        public float $minDurationSeconds = 1.0,
        public float $maxDurationSeconds = 60.0,
        public string $promptParameter = 'prompt',
        public ?string $durationParameter = 'duration',
        public bool $generatesVocals = false,
        public array $payloadDefaults = [],
        public array $payloadParameters = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: $config['label'] ?? $key,
            endpoint: $config['endpoint'] ?? null,
            costPerRequestUsd: (float) ($config['cost_per_request_usd'] ?? 0.0),
            costPerSecondUsd: (float) ($config['cost_per_second_usd'] ?? 0.0),
            minDurationSeconds: (float) ($config['min_duration_seconds'] ?? 1.0),
            maxDurationSeconds: (float) ($config['max_duration_seconds'] ?? 60.0),
            promptParameter: (string) ($config['prompt_parameter'] ?? 'prompt'),
            durationParameter: array_key_exists('duration_parameter', $config)
                ? ($config['duration_parameter'] === null ? null : (string) $config['duration_parameter'])
                : 'duration',
            generatesVocals: (bool) ($config['generates_vocals'] ?? false),
            payloadDefaults: (array) ($config['payload_defaults'] ?? []),
            payloadParameters: array_values(array_map('strval', $config['payload_parameters'] ?? [])),
        );
    }

    /**
     * What a track of this length costs on this model.
     *
     * Rounded to six places rather than two: at $0.0002 a second, two places
     * would round a real charge to zero and the budget cap would stop counting
     * music at all.
     */
    public function costForSeconds(float $seconds): float
    {
        return round(
            $this->costPerRequestUsd + ($this->clampDuration($seconds) * $this->costPerSecondUsd),
            6,
        );
    }

    /**
     * The length this model will actually render, given what the timeline wants.
     *
     * Clamping rather than refusing is right here and nowhere else in this
     * codebase: the assembler loops the music input (-stream_loop -1), so a bed
     * shorter than the video is a quality compromise — an audible repeat —
     * rather than a wrong result. Refusing a 7-minute project outright because
     * no model renders 7 minutes in one pass would be worse.
     */
    public function clampDuration(float $seconds): float
    {
        return round(min(max($seconds, $this->minDurationSeconds), $this->maxDurationSeconds), 2);
    }

    /**
     * Would this model's own output be looped to cover the given timeline?
     *
     * Reported to the owner rather than hidden: a 60-second loop repeating six
     * times over a 6-minute video sounds cheap, and that is a decision to make
     * knowingly.
     */
    public function loopsToCover(float $seconds): bool
    {
        return $seconds > $this->maxDurationSeconds + 0.01;
    }

    /**
     * Is this model guaranteed to come back without a sung vocal line?
     *
     * True either because the model cannot sing at all (Stable Audio 3 is
     * instrumental and sound design only) or because the registry sends it
     * something that switches singing off. A prompt asking politely for "no
     * vocals" does not count — that is a hope, and this is a guarantee.
     */
    public function suppressesVocals(): bool
    {
        if (! $this->generatesVocals) {
            return true;
        }

        foreach (['instrumental', 'lyrics'] as $field) {
            if (array_key_exists($field, $this->payloadDefaults)) {
                return true;
            }
        }

        return false;
    }

    public function sendsParameter(string $name): bool
    {
        return in_array($name, $this->payloadParameters, true);
    }
}
