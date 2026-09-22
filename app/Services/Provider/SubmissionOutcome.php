<?php

namespace App\Services\Provider;

use App\Models\ProviderRequest;

/**
 * What came of trying to submit a generation.
 *
 * Distinguished rather than collapsed into a boolean because the owner needs
 * different things said to them: "generating", "already done", "already
 * running" and "this exact request failed before" are four situations, and only
 * one of them means money was just spent.
 */
readonly class SubmissionOutcome
{
    private function __construct(
        public ProviderRequest $request,
        public string $kind,
    ) {}

    public static function submitted(ProviderRequest $r): self
    {
        return new self($r, 'submitted');
    }

    public static function duplicateInFlight(ProviderRequest $r): self
    {
        return new self($r, 'duplicate_in_flight');
    }

    public static function alreadyCompleted(ProviderRequest $r): self
    {
        return new self($r, 'already_completed');
    }

    public static function previouslyFailed(ProviderRequest $r): self
    {
        return new self($r, 'previously_failed');
    }

    /**
     * Different work that hashed to the same fingerprint, and reseeding did not
     * separate them. Vanishingly unlikely, but it must not be silent.
     */
    public static function collided(ProviderRequest $r): self
    {
        return new self($r, 'collided');
    }

    public function wasSubmitted(): bool
    {
        return $this->kind === 'submitted';
    }

    /** True when this call cost nothing because the work already existed. */
    public function wasDeduplicated(): bool
    {
        return in_array($this->kind, ['duplicate_in_flight', 'already_completed'], true);
    }

    public function message(): string
    {
        return match ($this->kind) {
            'submitted' => 'Submitted to the provider.',
            'duplicate_in_flight' => 'An identical generation is already running; waiting for it instead of paying twice.',
            'already_completed' => 'An identical generation already succeeded; reusing its result.',
            'previously_failed' => 'This exact request failed before. Change the prompt or reseed before retrying.',
            'collided' => 'This shot collided with another generation and could not be separated. Edit its prompt and regenerate.',
        };
    }
}
