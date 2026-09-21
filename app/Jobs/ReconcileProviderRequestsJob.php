<?php

namespace App\Jobs;

use App\Contracts\QueueableVideoGenerator;
use App\Contracts\VideoGenerator;
use App\Models\ProviderRequest;
use App\Services\Provider\GenerationCompleter;

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
