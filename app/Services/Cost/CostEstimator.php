<?php

namespace App\Services\Cost;

use App\Contracts\ImageGenerator;
use App\Contracts\MusicGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Models\Project;
use App\Services\Provider\ModelRegistry;
use App\Services\Timing\NarrationEstimator;

/**
 * Estimates what a run will cost, and enforces the hard per-project cap
 * (FR-11, NFR-4).
 *
 * Prices come from the model registry (video) and from the bound drivers
 * (image, speech, music) — never from a table in this class, so a model swap or
 * a driver swap re-prices the estimate automatically.
 *
 * Note that this no longer depends on a video driver at all: a project can be
 * planned and costed with no provider configured, which is what lets the owner
 * see a price before deciding whether to pay for one.
 */
class CostEstimator
{
    public function __construct(
        protected ImageGenerator $image,
        protected SpeechSynthesizer $speech,
        protected MusicGenerator $music,
        protected NarrationEstimator $estimator,
        protected ModelRegistry $models,
    ) {}

    /**
     * Cost of rendering the shots that still need rendering, plus any audio that
     * does not exist yet. Work already paid for is excluded — re-running a stage
     * must not double-count (NFR-3).
     */
    public function estimateRemainingRun(Project $project): CostEstimate
    {
        $lineItems = [];

        $shotSeconds = (float) $project->shots()
            ->whereIn('status', [
                ...ShotStatus::needingRenderValues(),
                ShotStatus::Queued->value,
            ])
            ->sum('target_duration_seconds');

        if ($shotSeconds > 0) {
            // Priced against the model the project actually renders on. Using
            // the bound driver's rate instead would cost a project pinned to an
            // expensive model at whatever happens to be configured locally —
            // and a zero-cost fake would wave an unaffordable run straight past
            // the cap.
            $lineItems['Video clips ('.$this->formatSeconds($shotSeconds).')'] =
                $shotSeconds * $this->models->forProject($project)->costPerSecondUsd();
        }

        $unlockedCharacters = $project->characters()->whereNull('canonical_reference_asset_id')->count();
        if ($unlockedCharacters > 0) {
            $candidates = (int) config('studio.image.candidates_per_character', 3);
            $lineItems["Character references ({$unlockedCharacters} × {$candidates} candidates)"] =
                $unlockedCharacters * $candidates * $this->image->costPerImageUsd();
        }

        if ($project->narrationAsset() === null) {
            $characters = mb_strlen($this->narrationText($project));
            if ($characters > 0) {
                $lineItems["Narration ({$characters} characters)"] =
                    ($characters / 1000) * $this->speech->costPer1kCharactersUsd();
            }
        }

        if ($project->musicAsset() === null) {
            $minutes = $this->estimatedRuntimeSeconds($project) / 60;
            if ($minutes > 0) {
                $lineItems['Music ('.$this->formatSeconds($minutes * 60).')'] =
                    $minutes * $this->music->costPerMinuteUsd();
            }
        }

        return new CostEstimate(
            lineItems: $lineItems,
            alreadySpentUsd: $project->spentUsd(),
            budgetCapUsd: (float) $project->budget_cap_usd,
        );
    }

    /**
     * Estimate first, spend second. Every job that costs money calls this.
     *
     * @throws BudgetExceededException
     */
    public function assertWithinBudget(Project $project): CostEstimate
    {
        $estimate = $this->estimateRemainingRun($project);

        if ($estimate->exceedsBudget()) {
            throw new BudgetExceededException($estimate);
        }

        return $estimate;
    }

    /**
     * Cheap guard for a single additional charge (one shot regeneration, one
     * narration re-run) where building the whole estimate would be overkill.
     *
     * @throws BudgetExceededException
     */
    public function assertCanSpend(Project $project, float $additionalUsd, string $label): CostEstimate
    {
        $estimate = new CostEstimate(
            lineItems: [$label => $additionalUsd],
            alreadySpentUsd: $project->spentUsd(),
            budgetCapUsd: (float) $project->budget_cap_usd,
        );

        if ($estimate->exceedsBudget()) {
            throw new BudgetExceededException($estimate);
        }

        return $estimate;
    }

    /**
     * Total narration text across the project, in scene order.
     */
    public function narrationText(Project $project): string
    {
        return trim(implode(' ', $project->scenes()->pluck('narration')->all()));
    }

    /**
     * How long the finished video is expected to run. Uses planned shot lengths
     * once shots exist, and falls back to the narration estimate before that —
     * so a cost figure can be shown on the scene-review screen, before any shot
     * has been planned.
     */
    public function estimatedRuntimeSeconds(Project $project): float
    {
        $planned = (float) $project->shots()->sum('target_duration_seconds');

        if ($planned > 0) {
            return $planned;
        }

        return $this->estimator->estimateSeconds($this->narrationText($project));
    }

    protected function formatSeconds(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds).'s';
        }

        return floor($seconds / 60).'m '.round(fmod($seconds, 60)).'s';
    }
}
