<?php

namespace App\Contracts\Data;

use App\Enums\AspectRatio;

readonly class ClipRequest
{
    /**
     * @param  string|null  $referenceImagePath  absolute path to the locked character reference, driving image-to-video (FR-8). Null for shots with no character.
     * @param  bool  $muteNativeAudio  FR-14: for narrated projects the model's own audio track must be discarded so the TTS narration is the only voice.
     */
    public function __construct(
        public string $prompt,
        public float $durationSeconds,
        public AspectRatio $aspectRatio,
        public ?string $referenceImagePath = null,
        public ?int $seed = null,
        public bool $muteNativeAudio = true,
    ) {}
}
