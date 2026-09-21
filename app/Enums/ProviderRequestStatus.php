<?php

namespace App\Enums;

/**
 * Lifecycle of one call to a provider.
 *
 * Long-running generation is submitted, not awaited: the provider returns a
 * request id immediately and the result arrives later by webhook or by polling.
 * This enum is what lets a worker restart without losing track of work the
 * provider is already doing — and already charging for.
 */
enum ProviderRequestStatus: string
{
    /** Row created, nothing sent yet. Claimed by us, so nothing else may submit it. */
    case Pending = 'pending';

    /** Accepted by the provider; we hold a request id. */
    case InQueue = 'in_queue';

    /** The provider reports it is working. */
    case InProgress = 'in_progress';

    /** Finished; the output has been retrieved and stored. */
    case Completed = 'completed';

    case Failed = 'failed';

    /** Cancelled by us before completion. */
    case Cancelled = 'cancelled';

    /**
     * Still occupying provider capacity — and therefore still potentially
     * billable. A second submission of the same work would pay twice.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::InQueue, self::InProgress], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not yet submitted',
            self::InQueue => 'Queued at provider',
            self::InProgress => 'Generating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
