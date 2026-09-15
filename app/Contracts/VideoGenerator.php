<?php

namespace App\Contracts;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;

/**
 * Renders one shot to one short clip (FR-9).
 *
 * `supportedClipLengths()` is what makes the timing engine provider-agnostic:
 * FR-16 rounds a scene's required length up to the nearest length this model
 * will actually render, and FR-17 splits the scene when the requirement exceeds
 * the longest. Neither rule needs to know which model it is talking to.
 */
interface VideoGenerator
{
    public function generateClip(ClipRequest $request): GeneratedMedia;

    /**
     * Clip durations this model can produce, ascending.
     *
     * @return list<float>
     */
    public function supportedClipLengths(): array;

    public function costPerSecondUsd(): float;

    /**
     * True for models like Veo 3.1 that return their own audio track. The
     * pipeline strips it for narrated projects (FR-14) to prevent double audio.
     */
    public function emitsNativeAudio(): bool;

    public function modelName(): string;

    public function providerName(): string;
}
