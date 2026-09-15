<?php

namespace App\Contracts\Data;

use App\Enums\AspectRatio;

readonly class ImageRequest
{
    public function __construct(
        public string $prompt,
        public AspectRatio $aspectRatio,
        public ?int $seed = null,
        public ?string $label = null,
    ) {}
}
