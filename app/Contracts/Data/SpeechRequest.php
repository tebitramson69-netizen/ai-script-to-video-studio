<?php

namespace App\Contracts\Data;

readonly class SpeechRequest
{
    public function __construct(
        public string $text,
        public string $language = 'en',
        public ?string $voiceId = null,
    ) {}
}
