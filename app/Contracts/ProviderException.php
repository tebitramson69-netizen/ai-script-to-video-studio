<?php

namespace App\Contracts;

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
    ) {
        parent::__construct($message, 0, $previous);
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
