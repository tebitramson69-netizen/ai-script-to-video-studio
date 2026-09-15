<?php

namespace App\Contracts;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ImageRequest;

/**
 * Generates candidate character reference images (FR-5). The specific model is
 * not the mechanism — reference *locking* is (PRD §10.2).
 */
interface ImageGenerator
{
    public function generate(ImageRequest $request): GeneratedMedia;

    public function costPerImageUsd(): float;

    public function providerName(): string;
}
