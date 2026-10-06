<?php

namespace App\Jobs;

use App\Contracts\QueueableVideoGenerator;
use App\Contracts\VideoGenerator;
use App\Models\ProviderRequest;
use App\Services\Pipeline\ProgressSnapshot;
use App\Services\Provider\GenerationCompleter;
use Illuminate\Support\Facades\Cache;

/**
 * Sweeps outstanding provider requests and advances any that have finished.
 *
 * This is the primary completion path, not a fallback. Webhooks get lost,
 * arrive out of order, and — until their signature scheme is verified — cannot
 * be trusted to mutate billing state at all. Polling is slower and entirely
 * dependable, which is the right trade for money.
 *
 * Safe to run concurrently with a webhook: completion is idempotent.
 */
class ReconcileProviderRequestsJob extends StudioJob
{
    public int $tries = 1;

    public function __construct(
        /** Cap the sweep so one run cannot occupy a worker indefinitely. */
        public int $limit = 50,
    ) {}

    public function handle(VideoGenerator $video, GenerationCompleter $completer): void
    {
        // Recorded before the driver check, and before any work, because the
        // question this answers is "is the scheduler running at all" — not
        // "did this sweep find anything".
        //
        // Without it, a project whose provider requests are never collected is
        // indistinguishable from one whose provider is slow: the page shows work
        // in flight forever and says nothing. That is the failure mode of
        // running `queue:work` without `schedule:work`, which costs real money,
        // because fal has already been paid for a generation nothing collects.
        Cache::put(
            ProgressSnapshot::RECONCILER_HEARTBEAT_KEY,
            now()->timestamp,

            // Long enough that expiry never masquerades as a stalled scheduler.
            now()->addDay(),
        );

        // Released before the driver check, and deliberately not limited to
        // video: any adapter that claims and then fails to record an id strands
        // its subject the same way, whatever driver happens to be bound now.
        $this->releaseAbandonedClaims($completer);

        if (! $video instanceof QueueableVideoGenerator) {
            // The bound driver answers synchronously; nothing is ever outstanding.
            return;
        }

        ProviderRequest::query()
            ->outstanding()
            ->where('capability', 'video.clip')
            ->whereNotNull('provider_request_id')
            ->orderBy('submitted_at')
            ->limit($this->limit)
            ->get()
            ->each(function (ProviderRequest $request) use ($video, $completer) {
                // One stuck request must not stop the sweep — the others may be
                // finished and waiting to be collected.
                try {
                    $completer->reconcile($request, $video);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }

    /**
     * Fail claims that were written but never submitted.
     *
     * These are invisible to the sweep above, which requires a provider request
     * id — so without this pass they sit outstanding forever and their shot
     * never leaves "rendering". Observed live on 2026-10-05: a submit POST timed
     * out at 120s and left exactly this row.
     *
     * The age test is the safety. Inside the submit timeout another worker may
     * be in the middle of submitClip() right now, and releasing its claim is how
     * one shot becomes two charges. Past that window, no earlier submit can
     * still be running.
     */
    protected function releaseAbandonedClaims(GenerationCompleter $completer): void
    {
        $grace = (int) config('studio.fal.timeout_seconds', 120) + 60;

        ProviderRequest::query()
            ->outstanding()
            ->whereNull('provider_request_id')
            ->where('created_at', '<', now()->subSeconds($grace))
            ->orderBy('created_at')
            ->limit($this->limit)
            ->get()
            ->each(function (ProviderRequest $request) use ($completer) {
                try {
                    $completer->releaseAbandonedClaim($request);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }
}
