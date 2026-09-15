<?php

namespace App\Services\Timing;

/**
 * Estimates how long narration will take to speak, before any TTS call is made.
 *
 * This is a planning figure only (FR-16, ~150 wpm). It decides how long a clip
 * to buy and what the run will cost — both of which have to be known *before*
 * spending money. Once the TTS provider returns real audio, the measured
 * duration replaces this estimate everywhere it matters.
 */
class NarrationEstimator
{
    public function __construct(protected int $wordsPerMinute = 150) {}

    public static function fromConfig(): self
    {
        return new self((int) config('studio.audio.words_per_minute', 150));
    }

    public function estimateSeconds(string $text): float
    {
        $words = $this->countWords($text);

        if ($words === 0) {
            return 0.0;
        }

        $seconds = ($words / max(1, $this->wordsPerMinute)) * 60;

        // Punctuation is pause. Without this, short punchy lines are estimated
        // far too fast and the clip bought for them is too short.
        $pauses = preg_match_all('/[.!?,;:]/', $text) ?: 0;
        $seconds += $pauses * 0.18;

        return round($seconds, 2);
    }

    public function countWords(string $text): int
    {
        $text = trim(strip_tags($text));

        if ($text === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $text) ?: []);
    }

    public function wordsPerMinute(): int
    {
        return $this->wordsPerMinute;
    }
}
