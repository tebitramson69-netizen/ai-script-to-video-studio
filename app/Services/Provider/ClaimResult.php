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
     */
    public function isDuplicateInFlight(): bool
    {
        return ! $this->isNew && $this->request->isActive();
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
