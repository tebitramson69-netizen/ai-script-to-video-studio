<?php

namespace App\Exceptions;

use App\Models\Shot;
use RuntimeException;

/**
 * Thrown when a shot is asked to regenerate while its previous render is still
 * in flight.
 *
 * Regeneration always re-rolls the seed, and the seed is part of the generation
 * fingerprint the ledger deduplicates on (NFR-3). So reseeding a shot whose
 * provider request is still outstanding produces a fingerprint the ledger has
 * never seen, the submit goes through, and the project pays for the same shot
 * twice — once for a clip nothing will ever attach.
 *
 * Refusing is the cheap half of the trade: the owner waits for the render to
 * land or fail, then regenerates. Cancelling the outstanding request first
 * would be the complete answer, but fal may bill an accepted submit anyway, so
 * it buys less certainty than it looks like it does.
 */
class ShotInFlightException extends RuntimeException
{
    public function __construct(public readonly Shot $shot)
    {
        parent::__construct(sprintf(
            'Shot #%d is still %s, so regenerating it now would buy the clip twice. '.
            'Wait for this render to finish or fail, then try again.',
            $shot->sequence,
            $shot->status->value,
        ));
    }
}
