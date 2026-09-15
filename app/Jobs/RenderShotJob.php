<?php

namespace App\Jobs;

use App\Contracts\Data\ClipRequest;
use App\Contracts\VideoGenerator;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\ProjectStateMachine;
use Throwable;

/**
 * Stage 4 (PRD §8): render one shot to one clip (FR-9).
 *
 * One job per shot, deliberately. NFR-2 requires that a single failed shot never
 * fails the project: with per-shot jobs a failure marks that shot Failed, the
 * rest of the render continues, and the owner regenerates just the one.
 */
class RenderShotJob extends StudioJob
{
    public function __construct(public int $shotId) {}

    public function handle(
        VideoGenerator $video,
        AssetRecorder $recorder,
        CostEstimator $costs,
        ProjectStateMachine $stateMachine,
    ): void {
        $shot = Shot::with(['project', 'characters.canonicalReference'])->find($this->shotId);

        if ($shot === null) {
            return;
        }

        // NFR-3: a re-queued job must not render and bill for a clip twice.
        if ($shot->status === ShotStatus::Rendered) {
            return;
        }

        $project = $shot->project;
        $estimatedCost = (float) $shot->target_duration_seconds * $video->costPerSecondUsd();

        $costs->assertCanSpend($project, $estimatedCost, "Shot #{$shot->sequence}");

        $shot->forceFill([
            'status' => ShotStatus::Rendering,
            'attempts' => $shot->attempts + 1,
        ])->save();

        $startedAt = microtime(true);

        try {
            $media = $video->generateClip(new ClipRequest(
                prompt: $shot->prompt,
                durationSeconds: (float) $shot->target_duration_seconds,
                aspectRatio: $project->aspect_ratio,
                referenceImagePath: $this->referenceImagePath($shot),
                seed: $shot->seed,

                // FR-14: narrated project, so any audio the model produces is
                // discarded — the TTS track is the only voice.
                muteNativeAudio: true,
            ));
        } catch (Throwable $e) {
            $shot->forceFill([
                'status' => ShotStatus::Failed,
                'error' => $e->getMessage(),
            ])->save();

            $recorder->recordFailure(
                $project,
                'video.clip',
                $video->providerName(),
                $e->getMessage(),
                model: $video->modelName(),
            );

            if ($this->shouldStopRetrying($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        $asset = $recorder->record(
            project: $project,
            type: AssetType::ShotClip,
            media: $media,
            operation: 'video.clip',
            provider: $video->providerName(),
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
        );

        $shot->forceFill([
            'asset_id' => $asset->getKey(),
            'status' => ShotStatus::Rendered,
            'cost_usd' => $media->costUsd,
            'error' => null,
            'rendered_at' => now(),
        ])->save();

        // Once every shot is rendered the project has reached SHOTS_READY. Each
        // shot job checks, so the last one to finish advances the project —
        // no coordinator job required.
        if ($project->fresh()->unrenderedShotCount() === 0) {
            $stateMachine->advanceTo($project->fresh(), ProjectStatus::ShotsReady);
        }
    }

    /**
     * FR-6: the locked canonical reference drives image-to-video for this shot.
     *
     * With several characters in one shot, the first locked reference is used —
     * P1 accepts imperfect consistency (PRD §7); multi-reference composition is
     * Phase 2 work.
     */
    protected function referenceImagePath(Shot $shot): ?string
    {
        foreach ($shot->characters as $character) {
            $reference = $character->canonicalReference;

            if ($reference !== null && $reference->exists()) {
                return $reference->absolutePath();
            }
        }

        return null;
    }

    public function failed(Throwable $e): void
    {
        Shot::whereKey($this->shotId)->update([
            'status' => ShotStatus::Failed->value,
            'error' => $e->getMessage(),
        ]);
    }
}
