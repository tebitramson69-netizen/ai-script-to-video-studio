<?php

namespace App\Services\Provider;

use App\Contracts\ProviderException;
use App\Contracts\QueueableVideoGenerator;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Models\ProviderRequest;
use App\Models\Shot;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\ProjectStateMachine;
use Throwable;

/**
 * Turns a finished provider request into a stored asset and an updated shot.
 *
 * Deliberately shared between the poller and (later) the webhook. Those are two
 * routes to the same event, and they will sometimes both fire for the same
 * request — so completion has to be idempotent regardless of who calls it.
 */
class GenerationCompleter
{
    public function __construct(
        protected GenerationLedger $ledger,
        protected AssetRecorder $recorder,
        protected ProjectStateMachine $stateMachine,
    ) {}

    /**
     * Advance one outstanding request as far as the provider allows.
     *
     * @return bool whether the request reached a terminal state
     */
    public function reconcile(ProviderRequest $request, QueueableVideoGenerator $video): bool
    {
        // Already finished — a webhook and a poll racing is normal, not an error.
        if ($request->status->isTerminal()) {
            return true;
        }

        if ($request->provider_request_id === null) {
            return false;
        }

        try {
            $status = $video->checkStatus($request->provider_request_id);
        } catch (ProviderException $e) {
            // A status check failing does not mean the generation failed. Leave
            // the request outstanding and try again next sweep, unless the
            // provider says the problem is permanent.
            if ($e->retryable) {
                return false;
            }

            $this->fail($request, ProviderFailureReason::Unknown, $e->getMessage());

            return true;
        }

        if ($status === ProviderRequestStatus::Failed) {
            $this->fail($request, ProviderFailureReason::ProviderError, 'The provider reported the generation failed.');

            return true;
        }

        if ($status !== ProviderRequestStatus::Completed) {
            // Still working. Record the finer-grained state so the UI can say
            // "generating" rather than "queued".
            if ($request->status !== $status) {
                $request->forceFill(['status' => $status])->save();
            }

            return false;
        }

        return $this->collect($request, $video);
    }

    /**
     * Fetch the finished media, store it, and point the shot at it.
     */
    protected function collect(ProviderRequest $request, QueueableVideoGenerator $video): bool
    {
        try {
            $media = $video->fetchResult($request->provider_request_id);
        } catch (ProviderException $e) {
            if ($e->retryable) {
                return false;
            }

            $this->fail($request, $e->reason ?? ProviderFailureReason::Unknown, $e->getMessage());

            return true;
        } catch (Throwable $e) {
            $this->fail($request, ProviderFailureReason::Unknown, $e->getMessage());

            return true;
        }

        $project = $request->project;

        $asset = $this->recorder->record(
            project: $project,
            type: AssetType::ShotClip,
            media: $media,
            operation: 'video.clip',
            provider: $video->providerName(),
        );

        $this->ledger->markCompleted(
            $request,
            assetId: $asset->getKey(),

            // Prefer whatever the provider actually charged; fall back to our
            // estimate so project spend stays approximately right until the
            // real figure arrives.
            actualCostUsd: $media->meta['actual_cost_usd'] ?? null,
            outputUrl: $media->meta['output_url'] ?? null,
            response: $media->meta,
        );

        $this->attachToShot($request, $asset->getKey(), (float) $asset->cost_usd);

        return true;
    }

    /**
     * Every shot waiting on this request gets the result — including any that
     * were deduplicated onto it rather than submitting their own.
     */
    protected function attachToShot(ProviderRequest $request, int $assetId, float $costUsd): void
    {
        $shot = $request->subject;

        if (! $shot instanceof Shot) {
            return;
        }

        $shot->forceFill([
            'asset_id' => $assetId,
            'status' => ShotStatus::Rendered,
            'cost_usd' => $costUsd,
            'error' => null,
            'rendered_at' => now(),
        ])->save();

        $project = $shot->project->fresh();

        if ($project !== null && $project->unrenderedShotCount() === 0) {
            $this->stateMachine->advanceTo($project, ProjectStatus::ShotsReady);
        }
    }

    protected function fail(ProviderRequest $request, ProviderFailureReason $reason, string $message): void
    {
        $this->ledger->markFailed($request, $reason, $message);

        $shot = $request->subject;

        if ($shot instanceof Shot) {
            $shot->forceFill([
                'status' => ShotStatus::Failed,
                'error' => $reason->label().' — '.$reason->guidance(),
            ])->save();
        }
    }
}
