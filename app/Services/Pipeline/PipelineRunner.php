<?php

namespace App\Services\Pipeline;

use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Jobs\AssembleProjectJob;
use App\Jobs\FinalizeAudioJob;
use App\Jobs\GenerateCharacterCandidatesJob;
use App\Jobs\GenerateMusicJob;
use App\Jobs\GenerateNarrationJob;
use App\Jobs\PlanShotsJob;
use App\Jobs\RenderShotJob;
use App\Jobs\StructureScriptJob;
use App\Models\Character;
use App\Models\Project;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Provider\ModelRegistry;
use Illuminate\Support\Facades\Bus;

/**
 * The only thing that dispatches pipeline work.
 *
 * Controllers call these methods; they never construct jobs themselves. That
 * keeps "what runs, in what order, and what it is allowed to cost" in one file
 * instead of spread across HTTP handlers.
 */
class PipelineRunner
{
    public function __construct(
        protected CostEstimator $costs,
        protected ProjectStateMachine $stateMachine,
    ) {}

    public function parseScript(Project $project): void
    {
        StructureScriptJob::dispatch($project->getKey());
    }

    /**
     * Generate reference candidates for every character that has none locked.
     *
     * @throws BudgetExceededException
     */
    public function generateCharacterCandidates(Project $project): void
    {
        $this->costs->assertWithinBudget($project);

        $project->characters()
            ->whereNull('canonical_reference_asset_id')
            ->get()
            ->each(fn (Character $c) => GenerateCharacterCandidatesJob::dispatch($c->getKey()));
    }

    public function planShots(Project $project): void
    {
        PlanShotsJob::dispatch($project->getKey());
    }

    /**
     * Render every shot that still needs it: never-rendered, failed, or stale.
     *
     * Already-rendered shots are skipped, so re-running this stage after a
     * partial failure costs only the shots that actually failed (NFR-3).
     *
     * @throws BudgetExceededException
     */
    public function renderShots(Project $project): int
    {
        $this->costs->assertWithinBudget($project);

        $pending = $project->shots()
            ->whereIn('status', ShotStatus::needingRenderValues())
            ->get();

        $pending->each(function (Shot $shot) {
            $shot->forceFill(['status' => ShotStatus::Queued])->save();
            RenderShotJob::dispatch($shot->getKey());
        });

        return $pending->count();
    }

    /**
     * Regenerate a single shot (FR-10). Invalidates assembly only (§8).
     *
     * @throws BudgetExceededException
     */
    public function regenerateShot(Project $project, Shot $shot, ?string $newPrompt = null): void
    {
        $this->stateMachine->shotInvalidated($project, $shot);

        // Always a fresh seed, prompt change or not. Re-rolling the same seed
        // through the same model returns the same clip, so an unedited
        // "regenerate" would spend money to reproduce the shot the owner just
        // rejected. It also keeps the generation fingerprint distinct, so the
        // idempotency index reads this as new work rather than a duplicate.
        $shot->forceFill([
            'prompt' => ($newPrompt !== null && trim($newPrompt) !== '') ? $newPrompt : $shot->prompt,
            'seed' => random_int(1, 2_000_000_000),
        ])->save();

        $this->costs->assertCanSpend(
            $project,
            (float) $shot->target_duration_seconds
                * app(ModelRegistry::class)->forProject($project)->costPerSecondUsd(),
            "Shot #{$shot->sequence}",
        );

        $shot->forceFill(['status' => ShotStatus::Queued])->save();
        RenderShotJob::dispatch($shot->getKey());
    }

    /**
     * Narration, then music, then mark VOICE_READY.
     *
     * Chained rather than dispatched in parallel: music length is derived from
     * the *measured* narration durations that the narration job writes back
     * (FR-16), so running them concurrently would size the music from stale
     * estimates.
     *
     * @throws BudgetExceededException
     */
    public function generateAudio(Project $project, bool $force = false): void
    {
        $this->costs->assertWithinBudget($project);

        Bus::chain([
            new GenerateNarrationJob($project->getKey(), $force),
            new GenerateMusicJob($project->getKey(), $force),
            new FinalizeAudioJob($project->getKey()),
        ])->dispatch();
    }

    /**
     * FR-15: regenerate narration on its own.
     *
     * Music is re-run unforced afterwards because re-measured narration may have
     * moved the timeline — if it did not, that step costs nothing.
     *
     * @throws BudgetExceededException
     */
    public function regenerateNarration(Project $project): void
    {
        $this->costs->assertWithinBudget($project);
        $this->stateMachine->audioInvalidated($project);

        Bus::chain([
            new GenerateNarrationJob($project->getKey(), force: true),
            new GenerateMusicJob($project->getKey()),
            new FinalizeAudioJob($project->getKey()),
        ])->dispatch();
    }

    /**
     * FR-15: regenerate music on its own, leaving narration untouched.
     *
     * @throws BudgetExceededException
     */
    public function regenerateMusic(Project $project): void
    {
        $this->costs->assertWithinBudget($project);
        $this->stateMachine->audioInvalidated($project);

        Bus::chain([
            new GenerateMusicJob($project->getKey(), force: true),
            new FinalizeAudioJob($project->getKey()),
        ])->dispatch();
    }

    public function export(Project $project): void
    {
        AssembleProjectJob::dispatch($project->getKey());
    }
}
