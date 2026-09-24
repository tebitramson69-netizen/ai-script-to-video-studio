<?php

namespace App\Contracts\Data;

/**
 * Everything the pipeline needs to know about one sound-effect model.
 *
 * The fifth and last of the capability DTOs. The constraint that shapes this one
 * is a hard ceiling on LENGTH: ElevenLabs Sound Effects V2 generates at most 22
 * seconds. Scenes are routinely longer, so an effect meant to sit under a whole
 * scene has to loop — which is why `supportsLoop` exists and why a model that
 * offers a real loop flag was preferred over one that does not. An effect looped
 * from a clip that was not generated to loop has an audible seam every 22
 * seconds, and the seam lands in the middle of the narration.
 *
 * Pricing is per EFFECT, not per second — $0.0194 on the default — which is why
 * this keeps `costPerEffectUsd` rather than the dual-rate shape the music DTO
 * needed. The budget question here is not "how long?" but "how many scenes?".
 */
readonly class SoundEffectModelCapabilities
{
    /**
     * @param  array<string, mixed>  $payloadDefaults  literal fields always sent to this model
     * @param  list<string>  $payloadParameters  optional fields this model is known to accept
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $endpoint,
        public float $costPerEffectUsd = 0.0,
        public float $minDurationSeconds = 0.5,
        public float $maxDurationSeconds = 22.0,
        public string $promptParameter = 'text',
        public ?string $durationParameter = 'duration_seconds',
        public bool $supportsLoop = false,
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
            costPerEffectUsd: (float) ($config['cost_per_effect_usd'] ?? 0.0),
            minDurationSeconds: (float) ($config['min_duration_seconds'] ?? 0.5),
            maxDurationSeconds: (float) ($config['max_duration_seconds'] ?? 22.0),
            promptParameter: (string) ($config['prompt_parameter'] ?? 'text'),
            durationParameter: array_key_exists('duration_parameter', $config)
                ? ($config['duration_parameter'] === null ? null : (string) $config['duration_parameter'])
                : 'duration_seconds',
            supportsLoop: (bool) ($config['supports_loop'] ?? false),
            payloadDefaults: (array) ($config['payload_defaults'] ?? []),
            payloadParameters: array_values(array_map('strval', $config['payload_parameters'] ?? [])),
        );
    }

    /**
     * The length this model will actually render for a scene of the given length.
     *
     * Clamped for the same reason the music bed is: the assembler loops the
     * effect to cover the scene, so a short effect repeats rather than falling
     * silent. Refusing a 40-second scene because no model renders 40 seconds of
     * ambience in one pass would be the wrong trade.
     */
    public function clampDuration(float $seconds): float
    {
        return round(min(max($seconds, $this->minDurationSeconds), $this->maxDurationSeconds), 2);
    }

    /**
     * Will this effect have to repeat to cover the scene it belongs to?
     *
     * Reported on the asset, because whether a repeat is acceptable depends on
     * the sound. Looped rain is invisible; a looped door slam is a woodpecker.
     */
    public function loopsToCover(float $seconds): bool
    {
        return $seconds > $this->maxDurationSeconds + 0.01;
    }

    public function sendsParameter(string $name): bool
    {
        return in_array($name, $this->payloadParameters, true);
    }
}
