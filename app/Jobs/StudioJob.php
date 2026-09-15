<?php

namespace App\Jobs;

use App\Contracts\ProviderException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Shared queue behaviour for every generation job (NFR-1, NFR-2).
 *
 * All generation is async — nothing in this app waits synchronously on a model
 * that may take minutes.
 */
abstract class StudioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /**
     * Exponential backoff. Rate limits and capacity errors from video providers
     * clear on the order of minutes, not milliseconds — retrying tightly just
     * burns the attempt budget before the provider has recovered.
     *
     * @return list<int> seconds
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    /**
     * Give a queued generation an hour to succeed across all its retries. Video
     * renders are genuinely slow.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * A provider that told us the failure is permanent should not be retried —
     * a rejected prompt will be rejected identically four times, and on a real
     * provider each attempt may still be billed.
     */
    protected function shouldStopRetrying(Throwable $e): bool
    {
        return $e instanceof ProviderException && ! $e->retryable;
    }
}
