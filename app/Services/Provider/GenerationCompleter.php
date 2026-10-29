<?php

namespace App\Services\Provider;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\ProviderException;
use App\Contracts\QueueableVideoGenerator;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Models\ProviderRequest;
use App\Models\Shot;
use App\Services\Database\ResilientWrite;
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

        // Written to the row before anything is recorded, so that
        // ProviderRequest::effectiveCostUsd() - the one definition of what a
        // generation cost - is the single source for the asset, the usage
        // record and the ledger alike. Not saved yet: markCompleted() below
        // persists it in the same write as the rest of the outcome.
        $request->actual_cost_usd = $this->reportedCostUsd($media);

        $asset = $this->recorder->record(
            project: $project,
            type: AssetType::ShotClip,
            media: $media->withCostUsd($request->effectiveCostUsd()),
            operation: 'video.clip',
            provider: $video->providerName(),
        );

        $this->ledger->markCompleted(
            $request,
            assetId: $asset->getKey(),
            actualCostUsd: $request->actual_cost_usd,
            outputUrl: $media->meta['output_url'] ?? null,
            response: $media->meta,
        );

        $this->attachToShot($request, $asset->getKey(), (float) $asset->cost_usd);

        return true;
    }

    /**
     * What the provider says it charged, or null if it said nothing.
     *
     * Kept separate from the fallback on purpose. provider_requests.actual_cost_usd
     * means "what we were told", and costVarianceUsd() compares it against the
     * estimate to show whether the rates in config/studio.php are drifting — so
     * writing an estimate into it would destroy the only signal that the rates
     * are wrong.
     *
     * A reported zero is treated as nothing reported. fal sends no cost field
     * for Kling at all, and a parsed 0.00 is far more likely to be a mapping
     * artefact than a genuinely free generation; under the over-estimate rule
     * the estimate is the safer number for the cap either way.
     */
    protected function reportedCostUsd(GeneratedMedia $media): ?float
    {
        $reported = $media->meta['actual_cost_usd'] ?? null;

        if (! is_numeric($reported) || (float) $reported <= 0.0) {
            return null;
        }

        return (float) $reported;
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

        // Retried on a lost race: this is what makes the clip visible to the
        // owner and to the assembler. The asset and the usage record are
        // already written by now, so losing this leaves a project that has paid
        // for a shot it still shows as rendering.
        ResilientWrite::run(fn () => $shot->forceFill([
            'asset_id' => $assetId,
            'status' => ShotStatus::Rendered,
            'cost_usd' => $costUsd,
            'error' => null,
            'rendered_at' => now(),
        ])->save());

        $project = $shot->project->fresh();

        if ($project !== null && $project->unrenderedShotCount() === 0) {
            $this->stateMachine->advanceTo($project, ProjectStatus::ShotsReady);
        }
    }

    /**
     * Release a claim the provider was never told about.
     *
     * A row claimed but never submitted has no provider request id, so the
     * reconciliation sweep - which filters on a non-null id - can never reach
     * it, and its shot stays "rendering" for good with nothing to explain why.
     * Failing it puts the shot back into a state renderShots() will pick up.
     *
     * Routed through fail() rather than reimplemented, because releasing the
     * subject alongside the request is the part that is easy to forget.
     */
    public function releaseAbandonedClaim(ProviderRequest $request): void
    {
        $this->fail(
            $request,
            ProviderFailureReason::Timeout,
            'Claimed but never submitted — the request to the provider did not complete. '.
            'Released so the shot can be rendered again. Check the provider dashboard first: '.
            'the original attempt may still have been accepted and charged.',
        );
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
