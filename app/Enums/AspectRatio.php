<?php

namespace App\Enums;

/**
 * Set once at project creation (FR-2) and passed to every shot call. Changing it
 * after shots exist requires regenerating them, so the UI locks it.
 */
enum AspectRatio: string
{
    case Landscape = '16:9';
    case Portrait = '9:16';
    case Square = '1:1';

    /** @return array{0:int,1:int} width, height in pixels */
    public function dimensions(): array
    {
        return match ($this) {
            self::Landscape => [1920, 1080],
            self::Portrait => [1080, 1920],
            self::Square => [1080, 1080],
        };
    }

    public function width(): int
    {
        return $this->dimensions()[0];
    }

    public function height(): int
    {
        return $this->dimensions()[1];
    }

    public function label(): string
    {
        return match ($this) {
            self::Landscape => '16:9 — landscape (YouTube)',
            self::Portrait => '9:16 — portrait (TikTok, Shorts, Reels)',
            self::Square => '1:1 — square',
        };
    }
}
