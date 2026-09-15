<?php

namespace App\Contracts\Data;

readonly class SoundEffectRequest
{
    public function __construct(
        public string $description,
        public float $durationSeconds = 2.0,
    ) {}
}
