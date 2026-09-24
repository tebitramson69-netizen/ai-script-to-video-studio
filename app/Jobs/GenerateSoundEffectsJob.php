<?php

namespace App\Jobs;

use App\Contracts\Data\SoundEffectRequest;
use App\Contracts\SoundEffectGenerator;
use App\Enums\AssetType;
use App\Models\Asset;
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
            count($pending) * $sfx->costPerEffectUsd(),
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
        $scenes = $project->scenes()
            ->with(['shots', 'assets'])
            ->whereNotNull('sfx_cue')
            ->where('sfx_cue', '!=', '')
            ->get();

        return $scenes
            ->filter(fn (Scene $scene) => $this->sceneDuration($scene) > 0)
            ->filter(fn (Scene $scene) => $this->force || ! $this->stillCovered($scene))
            ->values()
            ->all();
    }

    /**
     * Does this scene already have an effect worth keeping?
     *
     * Deliberately laxer than the music check, which demands the track match the
     * timeline within a second. An effect is LOOPED to cover its scene, so a
     * scene growing by three seconds does not invalidate it — the loop simply
     * runs a little longer. What does invalidate it is the cue changing, because
     * then the sound itself is wrong.
     */
    protected function stillCovered(Scene $scene): bool
    {
        $effect = $scene->assets->firstWhere('type', AssetType::SoundEffect);

        if (! $effect instanceof Asset || ! $effect->exists()) {
            return false;
        }

        return (string) ($effect->meta['description'] ?? '') === (string) $scene->sfx_cue;
    }

    protected function sceneDuration(Scene $scene): float
    {
        return round($scene->timelineDurationSeconds(), 3);
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
