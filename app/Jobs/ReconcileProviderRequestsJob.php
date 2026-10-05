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
}
