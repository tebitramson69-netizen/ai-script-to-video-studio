<?php

namespace App\Jobs;

use App\Contracts\Data\ClipRequest;
use App\Contracts\ProviderException;
use App\Contracts\QueueableVideoGenerator;
use App\Contracts\VideoGenerator;
use App\Enums\AssetType;
use App\Enums\GenerationMode;
use App\Enums\ProjectStatus;
use App\Enums\ProviderFailureReason;
use App\Enums\ShotStatus;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Provider\ModelRegistry;
use App\Services\Provider\ShotSubmitter;
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

        $request = $this->buildClipRequest($shot, $video);

        // Request-aware: resolution and the audio toggle both move the rate.
        $costs->assertCanSpend($project, $video->estimateCostUsd($request), "Shot #{$shot->sequence}");

        // A queueing provider is handed the work and released. The request id
        // outlives this worker, so a restart between submission and collection
        // loses nothing — the reconciler picks it up from the ledger.
        if ($video instanceof QueueableVideoGenerator) {
            $this->submitAsync($shot, $request, $video);

            return;
        }

        $shot->forceFill([
            'status' => ShotStatus::Rendering,
            'attempts' => $shot->attempts + 1,
        ])->save();

        $startedAt = microtime(true);

        try {
            $media = $video->generateClip($request);
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
     * Hand the shot to a queueing provider.
     *
     * Failure here is a submission failure, not a generation failure: nothing
     * has been generated yet, so a transient error is safe to rethrow and let
     * the queue retry. The ledger claim survives, which is what stops the retry
     * from becoming a second paid submission.
     */
    protected function submitAsync(Shot $shot, ClipRequest $request, QueueableVideoGenerator $video): void
    {
        try {
            $outcome = app(ShotSubmitter::class)->submit($shot, $request, $video);
        } catch (Throwable $e) {
            $shot->forceFill([
                'status' => ShotStatus::Failed,
                'error' => $e->getMessage(),
            ])->save();

            if ($this->shouldStopRetrying($e)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        // Identical work already succeeded: adopt its asset rather than paying
        // for the same clip a second time.
        if ($outcome->kind === 'already_completed' && $outcome->request->asset_id !== null) {
            $shot->forceFill([
                'asset_id' => $outcome->request->asset_id,
                'status' => ShotStatus::Rendered,
                'cost_usd' => 0,
                'rendered_at' => now(),
            ])->save();

            return;
        }

        if ($outcome->kind === 'collided' || $outcome->kind === 'previously_failed') {
            $shot->forceFill([
                'status' => ShotStatus::Failed,
                'error' => $outcome->message(),
            ])->save();
        }
    }

    /**
     * Assemble the provider request for this shot.
     *
     * The conditioning mode is decided here, once, from whether a locked
     * character reference is available — rather than left for each adapter to
     * infer from a nullable path.
     */
    protected function buildClipRequest(Shot $shot, VideoGenerator $video): ClipRequest
    {
        $reference = $this->referenceImagePath($shot);

        // The model this shot was PLANNED on, not the project's primary. With
        // per-shot selection the two differ by design, and the planned model is
        // the one whose clip-length ladder produced this shot's duration —
        // rendering on any other could ask for a length it cannot produce.
        $capabilities = app(ModelRegistry::class)->forShot($shot);

        $mode = ClipRequest::modeFor($reference);

        // Fall back rather than fail: a model that cannot do image-to-video can
        // still render the shot from its prompt, and losing the reference is a
        // consistency problem, not a broken pipeline.
        //
        // But the fallback is only available if the model has it. An
        // image-to-video-only model (Kling's i2v endpoint) cannot render a shot
        // with no locked character at all, and silently rewriting the request
        // to text-to-video would hand the adapter something the model refuses —
        // turning a knowable, free failure into a provider round trip.
        if (! $capabilities->supportsMode($mode)) {
            if (! $capabilities->supportsMode(GenerationMode::TextToVideo)) {
                throw ProviderException::because(
                    ProviderFailureReason::InvalidRequest,
                    sprintf(
                        '%s is image-to-video only and shot %d has no locked character '.
                        'reference to start from, so it cannot be rendered on this model. '.
                        'Lock a character onto the shot, or pin the project to a model that '.
                        'also does text-to-video.',
                        $capabilities->label,
                        $shot->id,
                    ),
                    'pipeline',
                );
            }

            $mode = GenerationMode::TextToVideo;
            $reference = null;
        }

        return new ClipRequest(
            prompt: $shot->prompt,
            durationSeconds: (float) $shot->target_duration_seconds,
            aspectRatio: $shot->project->aspect_ratio,
            mode: $mode,
            resolution: $capabilities->defaultResolution,
            referenceImagePath: $reference,
            seed: $shot->seed,
            modelKey: $capabilities->key,

            // FR-14: a narrated project must carry exactly one voice. On a model
            // with a native-audio toggle the adapter forwards this so the audio
            // is never generated — which is also the cheaper rate.
            muteNativeAudio: true,
        );
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
