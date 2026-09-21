<?php

namespace App\Contracts\Data;

use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;

readonly class ClipRequest
{
    /**
     * @param  GenerationMode  $mode  which conditioning the adapter should use — and therefore, on most providers, which endpoint. Stated explicitly rather than inferred from whether a reference image happens to be present.
     * @param  string|null  $referenceImagePath  absolute local path to the locked character reference driving image-to-video (FR-6, FR-8)
     * @param  list<string>  $referenceImagePaths  several references, for GenerationMode::ReferenceToVideo (Phase 2, FR-7)
     * @param  bool  $muteNativeAudio  FR-14. On a model with a native-audio toggle the adapter MUST pass this to the provider so the audio is never generated — not generate it and strip it afterwards, which pays for a track we discard.
     */
    public function __construct(
        public string $prompt,
        public float $durationSeconds,
        public AspectRatio $aspectRatio,
        public GenerationMode $mode = GenerationMode::TextToVideo,
        public VideoResolution $resolution = VideoResolution::Hd720,
        public ?string $referenceImagePath = null,
        public array $referenceImagePaths = [],
        public ?int $seed = null,
        public bool $muteNativeAudio = true,
    ) {}

    /**
     * The mode implied by the references supplied.
     *
     * A convenience for callers that have images but no opinion about
     * conditioning; the adapter still receives an explicit mode either way.
     *
     * @param  list<string>  $many
     */
    public static function modeFor(?string $one, array $many = []): GenerationMode
    {
        return match (true) {
            count($many) > 1 => GenerationMode::ReferenceToVideo,
            $one !== null || count($many) === 1 => GenerationMode::ImageToVideo,
            default => GenerationMode::TextToVideo,
        };
    }

    /**
     * Every reference this request carries, whichever field it arrived in.
     *
     * @return list<string>
     */
    public function references(): array
    {
        return array_values(array_filter([
            $this->referenceImagePath,
            ...$this->referenceImagePaths,
        ]));
    }
}
