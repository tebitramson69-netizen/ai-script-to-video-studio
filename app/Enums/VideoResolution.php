<?php

namespace App\Enums;

/**
 * Output resolution. Affects price on every provider that charges per second,
 * so it is a first-class part of a cost estimate rather than a rendering detail.
 */
enum VideoResolution: string
{
    case Hd720 = '720p';
    case Hd1080 = '1080p';
    case Uhd4k = '4k';

    public function shortEdge(): int
    {
        return match ($this) {
            self::Hd720 => 720,
            self::Hd1080 => 1080,
            self::Uhd4k => 2160,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Hd720 => '720p',
            self::Hd1080 => '1080p (HD)',
            self::Uhd4k => '4K',
        };
    }
}
