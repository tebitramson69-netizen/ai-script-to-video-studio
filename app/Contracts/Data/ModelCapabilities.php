<?php

namespace App\Contracts\Data;

use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use InvalidArgumentException;

/**
 * Everything the pipeline needs to know about one video model, read from
 * config rather than hard-coded anywhere in the business logic.
 *
 * This is the single source of truth for a model's limits and prices. The
 * timing engine asks it for clip lengths, the cost estimator asks it for a
 * rate, and the adapter asks it which modes and resolutions are legal. Swapping
 * models is therefore a config edit — which is PRD NFR-6 stated as code.
 */
readonly class ModelCapabilities
{
    /**
     * @param  string  $key  our config key, e.g. 'veo-3.1-fast'
     * @param  string|null  $endpoint  the provider's model id, e.g. 'fal-ai/veo3.1/fast'
     * @param  list<GenerationMode>  $modes
     * @param  list<float>  $clipLengths  ascending
     * @param  list<VideoResolution>  $resolutions
     * @param  list<AspectRatio>  $aspectRatios
     * @param  array<string, array{no_audio: float, audio: float}>  $pricing  keyed by resolution value
     * @param  bool  $supportsNativeAudioToggle  true when the provider accepts an explicit "don't generate audio" flag — which is what makes the cheaper rate reachable (FR-14)
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $endpoint,
        public array $modes,
        public array $clipLengths,
        public array $resolutions,
        public array $aspectRatios,
        public array $pricing,
        public VideoResolution $defaultResolution,
        public bool $supportsNativeAudioToggle = false,
        public bool $emitsNativeAudio = false,

        /**
         * Optional request parameters this model is known to accept, e.g.
         * ['aspect_ratio', 'resolution', 'seed'].
         *
         * Opt-in rather than opt-out because the two mistakes are not
         * symmetrical: omitting a parameter the model would have accepted
         * gives you its default, while sending one it does not accept gives
         * you a 4xx that reads like a wrong URL.
         *
         * @var list<string>
         */
        public array $payloadParameters = [],
    ) {}

    /**
     * Build from a config/studio.php `video_models` entry.
     *
     * Accepts either the rich `pricing` map or a flat `cost_per_second_usd`
     * scalar. The flat form exists because a model with one price for every
     * resolution is common, and writing the same number six times invites it to
     * drift.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $resolutions = array_map(
            fn (string $r) => VideoResolution::from($r),
            $config['resolutions'] ?? ['720p'],
        );

        $default = isset($config['default_resolution'])
            ? VideoResolution::from($config['default_resolution'])
            : $resolutions[0];

        return new self(
            key: $key,
            label: $config['label'] ?? $key,
            endpoint: $config['endpoint'] ?? null,
            modes: array_map(
                fn (string $m) => GenerationMode::from($m),
                $config['modes'] ?? ['text_to_video'],
            ),
            clipLengths: self::sortedLengths($config['clip_lengths'] ?? [5, 8]),
            resolutions: $resolutions,
            aspectRatios: array_map(
                fn (string $a) => AspectRatio::from($a),
                $config['aspect_ratios'] ?? ['16:9', '9:16'],
            ),
            pricing: self::normalisePricing($config, $resolutions),
            defaultResolution: $default,
            supportsNativeAudioToggle: (bool) ($config['supports_native_audio_toggle'] ?? false),
            emitsNativeAudio: (bool) ($config['emits_native_audio'] ?? false),
            payloadParameters: array_values(array_map(
                'strval',
                $config['payload_parameters'] ?? ['aspect_ratio'],
            )),
        );
    }

    /**
     * Price per second of output for a given resolution and audio setting.
     *
     * The audio dimension is not cosmetic: on Veo 3.1 the documented rate for
     * 720p/1080p halves from $0.40 to $0.20 when audio generation is turned off,
     * and every narrated project in this system turns it off (FR-14). Costing
     * without that dimension would overstate every estimate by 2x.
     */
    public function costPerSecondUsd(?VideoResolution $resolution = null, bool $withAudio = false): float
    {
        $resolution ??= $this->defaultResolution;
        $band = $this->pricing[$resolution->value] ?? null;

        if ($band === null) {
            throw new InvalidArgumentException(
                "Model '{$this->key}' has no price for resolution {$resolution->value}."
            );
        }

        return (float) ($withAudio ? $band['audio'] : $band['no_audio']);
    }

    /**
     * Whether the adapter should put this optional parameter on the wire.
     */
    public function sendsParameter(string $name): bool
    {
        return in_array($name, $this->payloadParameters, true);
    }

    public function supportsMode(GenerationMode $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }

    public function supportsResolution(VideoResolution $resolution): bool
    {
        return in_array($resolution, $this->resolutions, true);
    }

    public function supportsAspectRatio(AspectRatio $ratio): bool
    {
        return in_array($ratio, $this->aspectRatios, true);
    }

    public function maxClipLengthSeconds(): float
    {
        // Copied to a local first: end() takes its argument by reference, and a
        // readonly property cannot be passed that way.
        $lengths = $this->clipLengths;

        return (float) end($lengths);
    }

    public function minClipLengthSeconds(): float
    {
        $lengths = $this->clipLengths;

        return (float) reset($lengths);
    }

    /**
     * @param  list<float|int>  $lengths
     * @return list<float>
     */
    protected static function sortedLengths(array $lengths): array
    {
        $lengths = array_values(array_unique(array_filter(
            array_map('floatval', $lengths),
            fn (float $l) => $l > 0,
        )));

        if ($lengths === []) {
            throw new InvalidArgumentException('A video model must declare at least one clip length.');
        }

        sort($lengths);

        return $lengths;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<VideoResolution>  $resolutions
     * @return array<string, array{no_audio: float, audio: float}>
     */
    protected static function normalisePricing(array $config, array $resolutions): array
    {
        if (isset($config['pricing']) && is_array($config['pricing'])) {
            $pricing = [];

            foreach ($config['pricing'] as $resolution => $band) {
                $pricing[$resolution] = [
                    'no_audio' => (float) ($band['no_audio'] ?? $band['audio'] ?? 0.0),
                    'audio' => (float) ($band['audio'] ?? $band['no_audio'] ?? 0.0),
                ];
            }

            return $pricing;
        }

        // Flat fallback: one rate for everything.
        $flat = (float) ($config['cost_per_second_usd'] ?? 0.0);

        return array_reduce(
            $resolutions,
            function (array $carry, VideoResolution $r) use ($flat) {
                $carry[$r->value] = ['no_audio' => $flat, 'audio' => $flat];

                return $carry;
            },
            [],
        );
    }
}
