<?php

namespace App\Contracts\Data;

readonly class CharacterDraft
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
