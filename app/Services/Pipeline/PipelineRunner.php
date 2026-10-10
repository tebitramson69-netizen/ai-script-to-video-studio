<?php

namespace App\Services\Pipeline;

use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\ShotInFlightException;
use App\Jobs\AssembleProjectJob;
use App\Jobs\FinalizeAudioJob;
use App\Jobs\GenerateCharacterCandidatesJob;
use App\Jobs\GenerateMusicJob;
use App\Jobs\GenerateNarrationJob;
use App\Jobs\GenerateSoundEffectsJob;
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
        // Refused before the cap is even consulted, because this one is not
        // about affording the work - it is about buying it twice. The reseed
        // below changes the generation fingerprint, so the ledger stops
        // recognising the outstanding request as the same work and lets a
        // second submit through.
        if (in_array($shot->status, [ShotStatus::Queued, ShotStatus::Rendering], true)) {
            throw new ShotInFlightException($shot);
        }

        // Checked before anything is touched. The charge does not depend on the
        // prompt or the seed, so there is no reason to mutate the shot first -
        // and doing so left a refused regeneration with its clip invalidated,
        // its seed re-rolled and no replacement coming.
        //
        // Priced at THIS SHOT's model, not the project's primary. With per-shot
        // selection a character shot renders on the companion model, and
        // RenderShotJob charges estimateCostUsd() against the request - so
        // pricing the gate at the primary's rate gated a $0.20/s shot at
        // $0.07/s, waved it through, and left the clip invalidated when the job
        // then refused. estimateRemainingRun() already groups by shot model for
        // exactly this reason; this call site was missed.
        $this->costs->assertCanSpend(
            $project,
            (float) $shot->target_duration_seconds
                * app(ModelRegistry::class)->forShot($shot)->costPerSecondUsd(),
            "Shot #{$shot->sequence}",
        );

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

            // After music, because both are sized against the same timeline and
            // sound effects are the cheaper of the two to lose if the budget cap
            // stops the chain here. A no-op on the usual script, where the
            // structurer found no ambience to cue.
            new GenerateSoundEffectsJob($project->getKey(), $force),

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
        // The FORCED charge, not the remaining-run estimate: after a finished
        // run there is nothing remaining, so that estimate reads $0.00 and
        // would wave through a full re-synthesis.
        $this->costs->assertCanSpend(
            $project,
            $this->costs->forcedNarrationUsd($project),
            'Narration (regenerate)',
        );
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
        $this->costs->assertCanSpend(
            $project,
            $this->costs->forcedMusicUsd($project),
            'Music (regenerate)',
        );
        $this->stateMachine->audioInvalidated($project);

        Bus::chain([
            new GenerateMusicJob($project->getKey(), force: true),
            new FinalizeAudioJob($project->getKey()),
        ])->dispatch();
    }

    /**
     * FR-15: regenerate the per-scene sound effects on their own.
     *
     * Separate from music because the two go wrong for different reasons. Music
     * comes back with the wrong mood for the whole video; an effect comes back
     * wrong for one scene, usually because the cue that produced it was a false
     * positive. Re-running the effects alone lets that be fixed by editing the
     * cue without paying for the music again.
     *
     * @throws BudgetExceededException
     */
    public function regenerateSoundEffects(Project $project): void
    {
        $this->costs->assertCanSpend(
            $project,
            $this->costs->forcedSoundEffectsUsd($project),
            'Sound effects (regenerate)',
        );
        $this->stateMachine->audioInvalidated($project);

        Bus::chain([
            new GenerateSoundEffectsJob($project->getKey(), force: true),
            new FinalizeAudioJob($project->getKey()),
        ])->dispatch();
    }

    public function export(Project $project): void
    {
        AssembleProjectJob::dispatch($project->getKey());
    }
}
