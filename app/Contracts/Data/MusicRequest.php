<?php

namespace App\Contracts\Data;

readonly class MusicRequest
{
    public function __construct(
        public string $mood,
        public float $durationSeconds,
    ) {}
}
