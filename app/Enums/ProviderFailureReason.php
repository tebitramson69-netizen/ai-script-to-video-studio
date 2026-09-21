<?php

namespace App\Enums;

/**
 * Why a provider call failed, classified by what the right response is.
 *
 * The distinction that matters is retryable vs not. Retrying a safety rejection
 * or a malformed request fails identically every time, wastes the attempt
 * budget, and on a provider that bills failed calls costs real money. Retrying
 * a 429 or a dropped connection usually works.
 */
enum ProviderFailureReason: string
{
    /**
     * The provider's safety filter refused the input or the output.
     *
     * Explicitly NOT retryable and explicitly not lumped in with "invalid
     * request": the prompt is well-formed, the owner needs to rewrite it, and
     * the UI should say so rather than showing a generic failure.
     */
    case ContentRejected = 'content_rejected';

    /** Malformed parameters — a bug on our side, not a blip. */
    case InvalidRequest = 'invalid_request';

    /** Bad or missing API key. */
    case Authentication = 'authentication';

    /** Out of credit. Actionable by funding the account, not by waiting. */
    case InsufficientCredit = 'insufficient_credit';

    /** Too many requests. Backoff is exactly the right response. */
    case RateLimited = 'rate_limited';

    /** The call did not return in time. */
    case Timeout = 'timeout';

    /** Connection refused, reset, DNS — never reached the provider. */
    case NetworkError = 'network_error';

    /** The provider returned 5xx. Their problem, usually temporary. */
    case ProviderError = 'provider_error';

    /** Unrecognised. Treated as transient — see isRetryable(). */
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited,
            self::Timeout,
            self::NetworkError,
            self::ProviderError,

            // Conservative on purpose: an unclassified error is far more often a
            // transient blip than a novel permanent one, and the queue's attempt
            // budget bounds the damage either way.
            self::Unknown => true,

            self::ContentRejected,
            self::InvalidRequest,
            self::Authentication,
            self::InsufficientCredit => false,
        };
    }

    /**
     * Whether the owner can do something about this themselves.
     */
    public function isOwnerActionable(): bool
    {
        return in_array($this, [
            self::ContentRejected,
            self::InsufficientCredit,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ContentRejected => 'Rejected by the provider’s safety filter',
            self::InvalidRequest => 'Invalid request',
            self::Authentication => 'Provider authentication failed',
            self::InsufficientCredit => 'Provider account is out of credit',
            self::RateLimited => 'Rate limited',
            self::Timeout => 'Timed out',
            self::NetworkError => 'Network error',
            self::ProviderError => 'Provider error',
            self::Unknown => 'Unknown provider failure',
        };
    }

    /**
     * What to tell the owner. A safety rejection needs different advice from a
     * network blip, and "try again" is wrong for half of these.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::ContentRejected => 'Rewrite the shot prompt and regenerate. Retrying it unchanged will be refused again.',
            self::InvalidRequest => 'This is a bug in the adapter, not something you can fix by retrying.',
            self::Authentication => 'Check the provider API key in your .env.',
            self::InsufficientCredit => 'Top up the provider account, then re-render the failed shots.',
            self::RateLimited => 'The provider is throttling us. This retries automatically.',
            self::Timeout, self::NetworkError, self::ProviderError => 'A transient failure. This retries automatically.',
            self::Unknown => 'An unrecognised failure. This retries automatically; check the logs if it persists.',
        };
    }
}
