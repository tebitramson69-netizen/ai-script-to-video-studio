<?php

namespace App\Contracts;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SpeechRequest;

/**
 * Narration (FR-12). The returned media MUST carry a real duration — the
 * assembly step uses it as the master clock (FR-16), replacing the
 * words-per-minute estimate used for planning.
 */
interface SpeechSynthesizer
{
    public function synthesize(SpeechRequest $request): GeneratedMedia;

    /**
     * What a SINGLE synthesis request costs, which is not the character count
     * divided by a thousand: providers bill a request as a whole unit. The
     * pipeline voices one shot per request (FR-16), so an estimate that sums
     * the project's characters and divides once under-charges by the number of
     * shots. Ask this per request and add the answers up.
     */
    public function costForCharacters(int $characters): float;

    /** The headline rate. Informational — costForCharacters() is what bills. */
    public function costPer1kCharactersUsd(): float;

    public function providerName(): string;
}
