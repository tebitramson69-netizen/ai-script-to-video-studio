<?php

namespace App\Contracts;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\MusicRequest;

interface MusicGenerator
{
    public function generate(MusicRequest $request): GeneratedMedia;

    public function costPerMinuteUsd(): float;

    public function providerName(): string;
}
