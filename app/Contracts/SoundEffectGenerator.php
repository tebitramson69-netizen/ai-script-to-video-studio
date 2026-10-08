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

    /**
     * What ONE effect costs at the length the scene asks for.
     *
     * Not a count times a flat rate: fal bills this endpoint in seconds, so a
     * three-second ambience and a twenty-two-second one differ by 7x. Callers
     * must ask per effect, at its own duration, and add the answers up.
     */
    public function costForSeconds(float $seconds): float;

    /** The flat component, if the model has one. Informational. */
    public function costPerEffectUsd(): float;

    public function providerName(): string;
}
