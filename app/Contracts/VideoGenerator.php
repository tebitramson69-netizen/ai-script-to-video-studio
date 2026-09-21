<?php

namespace App\Contracts;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ModelCapabilities;

/**
 * Renders one shot to one short clip (FR-9).
 *
 * `capabilities()` is what makes the rest of the pipeline provider-agnostic:
 * the timing engine reads clip lengths from it (FR-16 rounds up to one of them,
 * FR-17 splits a scene that exceeds the longest), the cost estimator reads
 * rates from it, and the adapter reads the legal modes, resolutions and aspect
 * ratios from it. None of them needs to know which model is behind it.
 */
interface VideoGenerator
{
    public function generateClip(ClipRequest $request): GeneratedMedia;

    /**
     * Limits and prices of the model this generator is currently bound to.
     */
    public function capabilities(): ModelCapabilities;

    /**
     * What this specific request should cost, accounting for duration,
     * resolution and whether native audio is being generated.
     *
     * Request-aware rather than a flat rate because the audio toggle alone
     * changes the Veo 3.1 rate by 2x, and every narrated project disables it.
     */
    public function estimateCostUsd(ClipRequest $request): float;

    /**
     * Clip durations this model can produce, ascending.
     *
     * @return list<float>
     */
    public function supportedClipLengths(): array;

    /**
     * Headline rate: default resolution, audio disabled — i.e. what a narrated
     * Phase 1 project actually pays (FR-14). For anything else, use
     * estimateCostUsd(), which knows the request.
     */
    public function costPerSecondUsd(): float;

    /**
     * True for models like Veo 3.1 that can return their own audio track.
     * Whether they are *asked* to is decided per request by ClipRequest.
     */
    public function emitsNativeAudio(): bool;

    public function modelName(): string;

    public function providerName(): string;
}
