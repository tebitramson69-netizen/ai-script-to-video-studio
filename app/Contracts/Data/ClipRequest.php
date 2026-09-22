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

        /**
         * Registry key of the model to render on, e.g. 'veo-3-1-fast'.
         *
         * An aggregator hosts many models behind one credential, so the adapter
         * cannot know which endpoint to call unless the request says. Null means
         * "the driver's own default", which is all a single-model driver needs.
         */
        public ?string $modelKey = null,
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
     * The same request with a different seed.
     *
     * A new seed is what makes otherwise-identical work genuinely distinct —
     * both to the model, which returns a different clip, and to the
     * fingerprint, which stops treating it as a duplicate.
     */
    public function withSeed(int $seed): self
    {
        return new self(
            prompt: $this->prompt,
            durationSeconds: $this->durationSeconds,
            aspectRatio: $this->aspectRatio,
            mode: $this->mode,
            resolution: $this->resolution,
            referenceImagePath: $this->referenceImagePath,
            referenceImagePaths: $this->referenceImagePaths,
            seed: $seed,
            muteNativeAudio: $this->muteNativeAudio,
            modelKey: $this->modelKey,
        );
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
