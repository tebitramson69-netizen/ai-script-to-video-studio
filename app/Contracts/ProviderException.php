<?php

namespace App\Contracts;

use App\Enums\ProviderFailureReason;
use RuntimeException;

/**
 * Thrown by any driver when an external call fails.
 *
 * `$retryable` tells the queue whether to back off and try again (NFR-2) or to
 * fail the job immediately. A 429 or a 503 is retryable; a rejected prompt or a
 * malformed request is not, and retrying it just burns money and time.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly ?string $provider = null,
        ?\Throwable $previous = null,

        /**
         * Why it failed, when the adapter could tell. Carries the distinction
         * the queue needs — a safety rejection must never be retried, a 429
         * always should.
         */
        public readonly ?ProviderFailureReason $reason = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build from the taxonomy, taking retryability from the reason itself so
     * the two can never disagree.
     */
    public static function because(
        ProviderFailureReason $reason,
        string $message,
        ?string $provider = null,
        ?\Throwable $previous = null,
    ): self {
        return new self($message, $reason->isRetryable(), $provider, $previous, $reason);
    }

    public static function retryable(string $message, ?string $provider = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $provider, $previous);
    }

    public static function permanent(string $message, ?string $provider = null, ?\Throwable $previous = null): self
    {
        return new self($message, false, $provider, $previous);
    }
}
