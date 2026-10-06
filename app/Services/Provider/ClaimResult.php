<?php

namespace App\Services\Provider;

use App\Enums\ProviderRequestStatus;
use App\Models\ProviderRequest;

/**
 * The outcome of trying to claim a paid generation.
 */
readonly class ClaimResult
{
    public function __construct(
        public ProviderRequest $request,

        /** True when this caller won the claim and must do the submitting. */
        public bool $isNew,
    ) {}

    /**
     * An identical request is already in flight — wait for it rather than
     * paying for the same output twice.
     *
     * Requires a provider request id. Without one the provider was never
     * successfully told about this work, so there is nothing to wait for: the
     * reconciler filters on whereNotNull('provider_request_id') and would never
     * sweep it, leaving the shot "rendering" for good.
     *
     * That is not hypothetical. A submit whose POST timed out client-side left
     * exactly this row — Pending, no id — and the retry read it as in-flight and
     * declined to resubmit. The shot was stranded with no error shown
     * (2026-10-05).
     */
    public function isDuplicateInFlight(): bool
    {
        return ! $this->isNew
            && $this->request->isActive()
            && $this->request->provider_request_id !== null;
    }

    /**
     * A claim nobody ever managed to submit, old enough that no one still is.
     *
     * The age test is the safety: a claim younger than the submit timeout may
     * have a worker inside submitClip() at this very moment, and treating that
     * as abandoned is how one shot becomes two charges. Past the timeout, no
     * in-flight submit can still exist, so the row is genuinely orphaned.
     */
    public function isAbandonedClaim(int $graceSeconds): bool
    {
        if ($this->isNew || $this->request->provider_request_id !== null) {
            return false;
        }

        if ($this->request->status !== ProviderRequestStatus::Pending) {
            return false;
        }

        return $this->request->created_at !== null
            && $this->request->created_at->lt(now()->subSeconds($graceSeconds));
    }

    /**
     * The previous attempt failed for a reason the inputs did not cause, and
     * the provider never acknowledged it.
     *
     * Both halves matter. `isRetryable()` separates a timeout or a network blip
     * — where identical inputs would very likely succeed — from a content
     * rejection or an invalid request, where they would fail identically and
     * refusing is right. A null provider request id then confirms the provider
     * holds nothing: if it had an id, the reconciler owns that lifecycle and
     * resubmitting could pay for the same clip twice.
     *
     * Without this, a transient failure poisons the fingerprint permanently.
     * The owner presses Render, is told "this exact request failed before", and
     * has no way forward except reseeding — for a failure that was never about
     * the request.
     */
    public function isRetryableFailure(): bool
    {
        return ! $this->isNew
            && $this->request->status === ProviderRequestStatus::Failed
            && $this->request->provider_request_id === null
            && $this->request->isRetryable();
    }

    /**
     * Claimed, unsubmitted, and still inside the window where another worker
     * could legitimately be submitting it. Wait — do not submit alongside it.
     */
    public function isSubmissionInProgress(int $graceSeconds): bool
    {
        return ! $this->isNew
            && $this->request->provider_request_id === null
            && $this->request->status === ProviderRequestStatus::Pending
            && ! $this->isAbandonedClaim($graceSeconds);
    }

    /**
     * An identical request already succeeded; its asset is the answer.
     */
    public function isAlreadyCompleted(): bool
    {
        return ! $this->isNew
            && $this->request->status === ProviderRequestStatus::Completed;
    }
}
