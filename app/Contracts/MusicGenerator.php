<?php

namespace App\Contracts;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\MusicRequest;

interface MusicGenerator
{
    public function generate(MusicRequest $request): GeneratedMedia;

    /**
     * What a bed of this length will cost.
     *
     * A duration argument rather than a flat per-minute rate because providers
     * bill music both ways: ACE-Step charges by the second, Stable Audio 3
     * charges a flat fee per request however long the track is. A per-minute
     * rate cannot express the second kind without knowing the length, and
     * guessing it is how the budget cap starts lying.
     */
    public function costForSeconds(float $durationSeconds): float;

    public function providerName(): string;
}
