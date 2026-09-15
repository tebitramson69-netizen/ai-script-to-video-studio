<?php

namespace App\Services\Cost;

/**
 * A pre-run cost breakdown, shown to the owner before anything is spent (NFR-4).
 */
readonly class CostEstimate
{
    /**
     * @param  array<string, float>  $lineItems  label => USD
     */
    public function __construct(
        public array $lineItems,
        public float $alreadySpentUsd,
        public float $budgetCapUsd,
    ) {}

    public function estimatedUsd(): float
    {
        return round(array_sum($this->lineItems), 4);
    }

    /**
     * What the project will have cost in total if this run goes ahead.
     */
    public function projectedTotalUsd(): float
    {
        return round($this->alreadySpentUsd + $this->estimatedUsd(), 4);
    }

    public function remainingAfterRunUsd(): float
    {
        return round($this->budgetCapUsd - $this->projectedTotalUsd(), 4);
    }

    public function exceedsBudget(): bool
    {
        return $this->projectedTotalUsd() > $this->budgetCapUsd;
    }

    /**
     * @return array<string, float> line items with zero-cost entries dropped
     */
    public function significantLineItems(): array
    {
        return array_filter($this->lineItems, fn (float $v) => $v > 0.0);
    }
}
