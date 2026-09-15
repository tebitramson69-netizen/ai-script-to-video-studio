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

    public function costPer1kCharactersUsd(): float;

    public function providerName(): string;
}
