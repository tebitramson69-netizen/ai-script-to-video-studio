<?php

namespace App\Contracts\Data;

use App\Enums\AspectRatio;
use InvalidArgumentException;

/**
 * Everything the pipeline needs to know about one image model.
 *
 * The third sibling of ModelCapabilities and SpeechModelCapabilities. Kept
 * apart for the same reason: a video model is priced per second against a
 * clip-length ladder, a speech model per character against a language, and an
 * image model per image against a size preset. One shared class would carry
 * two thirds of its fields as null for every caller.
 *
 * `imageSizes` is the load-bearing field. FLUX does not take width and height —
 * it takes a preset name from a fixed set (square_hd, portrait_16_9,
 * landscape_16_9 and so on), so the project's aspect ratio has to be
 * translated. Getting that wrong is not cosmetic: a locked character reference
 * IS the framing for every image-to-video shot built from it, because that
 * endpoint takes no aspect_ratio of its own. A reference generated square on a
 * 16:9 project mis-frames every character shot in the video, at full price,
 * with nothing saying so.
 */
readonly class ImageModelCapabilities
{
    /**
     * @param  array<string, string>  $imageSizes  aspect ratio value => provider size preset
     * @param  list<string>  $payloadParameters
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $endpoint,
        public array $imageSizes,
        public float $costPerImageUsd,
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
            imageSizes: array_map('strval', $config['image_sizes'] ?? []),
            costPerImageUsd: (float) ($config['cost_per_image_usd'] ?? 0.0),
            payloadParameters: array_values(array_map('strval', $config['payload_parameters'] ?? [])),
        );
    }

    /**
     * The provider's size preset for this aspect ratio.
     *
     * Throws rather than falling back to a default. A silently substituted
     * shape would be paid for and then inherited by every image-to-video shot
     * the reference drives.
     */
    public function imageSizeFor(AspectRatio $ratio): string
    {
        $preset = $this->imageSizes[$ratio->value] ?? null;

        if ($preset === null || $preset === '') {
            throw new InvalidArgumentException(sprintf(
                '%s declares no image size for %s. It declares: %s.',
                $this->label,
                $ratio->value,
                $this->imageSizes === [] ? 'none' : implode(', ', array_keys($this->imageSizes)),
            ));
        }

        return $preset;
    }

    public function supportsAspectRatio(AspectRatio $ratio): bool
    {
        return ($this->imageSizes[$ratio->value] ?? '') !== '';
    }

    public function sendsParameter(string $name): bool
    {
        return in_array($name, $this->payloadParameters, true);
    }
}
