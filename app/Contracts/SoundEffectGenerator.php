<?php

namespace App\Contracts;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SoundEffectRequest;

/**
 * Per-scene SFX. Phase 2 (PRD §7) — the interface exists now so the assembly
 * timeline does not need reworking when it lands.
 */
interface SoundEffectGenerator
{
    public function generate(SoundEffectRequest $request): GeneratedMedia;

    public function costPerEffectUsd(): float;

    public function providerName(): string;
}
