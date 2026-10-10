<?php

namespace App\Jobs;

use App\Contracts\Data\SoundEffectRequest;
use App\Contracts\SoundEffectGenerator;
use App\Enums\AssetType;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use Throwable;

/**
 * Stage 5c (PRD §8): one ambience effect per scene that has a cue (FR-13).
 *
 * Unlike every other generation stage this one is OPTIONAL by construction. A
 * scene with no cue is skipped, and most scenes have no cue — the structurer only
 * sets one when the prose actually names a continuous sound. That is the whole
 * cost-control design: effects are priced per effect, so the bill is the number
 * of scenes with cues, and an effect nobody asked for is a sound that does not
 * belong in the finished video.
 *
 * The budget is checked for the WHOLE batch before the first request. Checking
 * per effect would let a six-scene project buy four of them and then fail, which
 * leaves the owner paying for a mix they cannot complete.
 */
class GenerateSoundEffectsJob extends StudioJob
{
    /**
     * @param  bool  $force  regenerate even where a usable effect already exists
     *                       (FR-15). Without it the stage is a no-op for scenes
     *                       whose effect still covers them.
     */
    public function __construct(
        public int $projectId,
        public bool $force = false,
    ) {}

    public function handle(
        SoundEffectGenerator $sfx,
        AssetRecorder $recorder,
        CostEstimator $costs,
    ): void {
        $project = Project::find($this->projectId);

        if ($project === null) {
            return;
        }

        $pending = $this->pendingScenes($project);

        if ($pending === []) {
            return;
        }

        // The whole batch, up front. A per-effect check would spend most of the
        // money and then refuse, which is the worst of both outcomes.
        $costs->assertCanSpend(
            $project,
            // Each at its own length: this endpoint bills by the second, so the
            // batch total is not the count times a flat rate.
            array_sum(array_map(
                fn (Scene $scene) => $sfx->costForSeconds($this->sceneDuration($scene)),
                $pending,
            )),
            sprintf('Sound effects (%d)', count($pending)),
        );

        foreach ($pending as $scene) {
            $startedAt = microtime(true);

            $media = $sfx->generate(new SoundEffectRequest(
                description: (string) $scene->sfx_cue,

                // The scene's own span on the finished timeline, not its planned
                // length: clips are trimmed or held to measured narration
                // (FR-18), so a planned figure would buy an effect that runs past
                // the cut.
                durationSeconds: $this->sceneDuration($scene),
            ));

            $effect = $recorder->record(
                project: $project,
                type: AssetType::SoundEffect,
                media: $media,
                operation: 'sfx.effect',
                provider: $sfx->providerName(),
                durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                scene: $scene,
            );

            // One effect per scene (Phase 1). Anything it replaced is dead weight
            // on disk; the usage record that says what it cost survives the
            // deletion.
            $scene->assets()
                ->where('type', AssetType::SoundEffect)
                ->whereKeyNot($effect->getKey())
                ->get()
                ->each->delete();
        }
    }

    /**
     * Scenes that want an effect and do not already have a usable one.
     *
     * @return list<Scene>
     */
    protected function pendingScenes(Project $project): array
    {
        return $project->scenes()
            ->with(['shots', 'assets'])
            ->cued()
            ->get()
            ->filter(fn (Scene $scene) => $scene->hasTimeline())
            // `force` is spent on the first attempt only — a retry after a
            // mid-batch provider failure must not re-buy the effects the
            // earlier attempt already paid for (StudioJob::isFirstAttempt()).
            ->filter(fn (Scene $scene) => ($this->force && $this->isFirstAttempt())
                || ! $scene->soundEffectIsCurrent())
            ->values()
            ->all();
    }

    /**
     * The length this scene's effect is bought for.
     *
     * No rounding. It used to round to three places, which is invisible on a
     * flat per-effect rate and is not invisible now that the rate is per second
     * and billable seconds are rounded UP: a 3.0004s scene priced 4 seconds in
     * the gate and 3 in the job, because only one of them rounded first.
     */
    protected function sceneDuration(Scene $scene): float
    {
        return $scene->timelineDurationSeconds();
    }

    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);

        if ($project !== null) {
            app(AssetRecorder::class)->recordFailure(
                $project,
                'sfx.effect',
                app(SoundEffectGenerator::class)->providerName(),
                $e->getMessage(),
            );
        }
    }
}
