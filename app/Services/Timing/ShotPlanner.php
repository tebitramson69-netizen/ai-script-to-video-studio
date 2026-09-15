<?php

namespace App\Services\Timing;

use App\Models\Scene;
use InvalidArgumentException;

/**
 * Implements the PRD's timing rules (FR-16, FR-17, FR-18).
 *
 *   FR-16 — Narration is the master clock. Estimate its duration, then set the
 *           shot's target length to cover it, rounded UP to the model's nearest
 *           supported clip length.
 *   FR-17 — If a scene's narration exceeds one clip's maximum, split it into
 *           multiple shots sharing the same character reference and environment.
 *   FR-18 — If narration is shorter than the clip, the surplus is trimmed at
 *           assembly. (Enacted in the assembler; recorded here as slack.)
 *
 * The planner never talks to a provider. It takes the model's supported clip
 * lengths as data, which is what lets the same rules hold for Kling, Veo, or the
 * local fake without a branch anywhere.
 */
class ShotPlanner
{
    public function __construct(protected NarrationEstimator $estimator) {}

    /**
     * @param  list<float>  $supportedClipLengths  ascending, from VideoGenerator::supportedClipLengths()
     * @return list<ShotPlan>
     */
    public function planScene(Scene $scene, array $supportedClipLengths): array
    {
        return $this->planNarration((string) $scene->narration, $supportedClipLengths);
    }

    /**
     * @param  list<float>  $supportedClipLengths
     * @return list<ShotPlan>
     */
    public function planNarration(string $narration, array $supportedClipLengths): array
    {
        $lengths = $this->normaliseLengths($supportedClipLengths);
        $maxClip = end($lengths);

        $narration = trim($narration);

        // A scene with no narration still needs a shot — it is a visual beat.
        // Give it the shortest clip the model offers.
        if ($narration === '') {
            return [new ShotPlan('', 0.0, reset($lengths))];
        }

        $duration = $this->estimator->estimateSeconds($narration);

        // FR-16: fits in one clip.
        if ($duration <= $maxClip) {
            return [new ShotPlan($narration, $duration, $this->roundUpToSupported($duration, $lengths))];
        }

        // FR-17: too long for one clip — split it.
        $plans = [];
        foreach ($this->splitToFit($narration, $maxClip) as $segment) {
            $segmentDuration = $this->estimator->estimateSeconds($segment);

            $plans[] = new ShotPlan(
                $segment,
                $segmentDuration,
                $this->roundUpToSupported($segmentDuration, $lengths),
            );
        }

        return $plans;
    }

    /**
     * Round a required duration up to the nearest clip length the model will
     * actually render. Never rounds down: a clip shorter than its narration
     * would cut the voice off mid-sentence.
     *
     * @param  list<float>  $lengths  ascending
     */
    public function roundUpToSupported(float $seconds, array $lengths): float
    {
        foreach ($lengths as $length) {
            if ($length >= $seconds) {
                return $length;
            }
        }

        // Longer than anything the model offers. Callers reach this only through
        // splitToFit(), which guarantees segments fit; returning the maximum is
        // the safe floor rather than an exception mid-render.
        return (float) end($lengths);
    }

    /**
     * Break narration into segments that each fit inside `$maxSeconds` of
     * speech, preferring sentence boundaries.
     *
     * @return list<string>
     */
    public function splitToFit(string $narration, float $maxSeconds): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($narration)) ?: [];
        $sentences = array_values(array_filter(array_map('trim', $sentences), fn ($s) => $s !== ''));

        $segments = [];
        $current = [];

        foreach ($sentences as $sentence) {
            // A single sentence longer than one clip cannot be kept whole.
            // Fall back to splitting it on word boundaries.
            if ($this->estimator->estimateSeconds($sentence) > $maxSeconds) {
                if ($current !== []) {
                    $segments[] = implode(' ', $current);
                    $current = [];
                }

                foreach ($this->splitWordsToFit($sentence, $maxSeconds) as $piece) {
                    $segments[] = $piece;
                }

                continue;
            }

            $candidate = [...$current, $sentence];

            if ($this->estimator->estimateSeconds(implode(' ', $candidate)) > $maxSeconds) {
                $segments[] = implode(' ', $current);
                $current = [$sentence];

                continue;
            }

            $current = $candidate;
        }

        if ($current !== []) {
            $segments[] = implode(' ', $current);
        }

        return $segments === [] ? [trim($narration)] : $segments;
    }

    /**
     * @return list<string>
     */
    protected function splitWordsToFit(string $sentence, float $maxSeconds): array
    {
        $words = preg_split('/\s+/', trim($sentence)) ?: [];

        $pieces = [];
        $current = [];

        foreach ($words as $word) {
            $candidate = [...$current, $word];

            if ($current !== [] && $this->estimator->estimateSeconds(implode(' ', $candidate)) > $maxSeconds) {
                $pieces[] = implode(' ', $current);
                $current = [$word];

                continue;
            }

            $current = $candidate;
        }

        if ($current !== []) {
            $pieces[] = implode(' ', $current);
        }

        return $pieces;
    }

    /**
     * @param  list<float>  $lengths
     * @return list<float> ascending, positive, de-duplicated
     */
    protected function normaliseLengths(array $lengths): array
    {
        $lengths = array_values(array_unique(array_filter(
            array_map('floatval', $lengths),
            fn (float $l) => $l > 0,
        )));

        if ($lengths === []) {
            throw new InvalidArgumentException(
                'The video model reported no usable clip lengths; timing cannot be planned.'
            );
        }

        sort($lengths);

        return $lengths;
    }
}
