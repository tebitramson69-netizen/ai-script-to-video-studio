<?php

namespace App\Services\Timing;

/**
 * One renderable shot, as planned but not yet persisted.
 */
readonly class ShotPlan
{
    public function __construct(
        /** The slice of the scene's narration this shot covers. */
        public string $narrationSegment,

        /** Estimated speaking time for that slice — what the timeline needs. */
        public float $narrationDurationSeconds,

        /** What we ask the model for: rounded UP to a supported clip length. */
        public float $targetDurationSeconds,
    ) {}

    /**
     * Seconds of clip bought beyond what the narration needs. At assembly this
     * is trimmed off (FR-18), so it is pure overspend — useful to surface when
     * the owner is choosing a model.
     */
    public function slackSeconds(): float
    {
        return round(max(0.0, $this->targetDurationSeconds - $this->narrationDurationSeconds), 2);
    }
}
