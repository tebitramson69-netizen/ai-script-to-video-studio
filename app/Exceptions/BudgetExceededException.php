<?php

namespace App\Exceptions;

use App\Services\Cost\CostEstimate;
use RuntimeException;

/**
 * Thrown when a run would push a project past its cap. The cap is hard (NFR-4):
 * this is not a warning the caller may ignore.
 */
class BudgetExceededException extends RuntimeException
{
    public function __construct(public readonly CostEstimate $estimate)
    {
        parent::__construct(sprintf(
            'This run is estimated at $%.2f. The project has already spent $%.2f, '.
            'which would bring the total to $%.2f against a cap of $%.2f. '.
            'Raise the budget cap or reduce the number of shots.',
            $estimate->estimatedUsd(),
            $estimate->alreadySpentUsd,
            $estimate->projectedTotalUsd(),
            $estimate->budgetCapUsd,
        ));
    }
}
