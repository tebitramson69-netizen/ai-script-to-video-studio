<?php

namespace App\Enums;

/**
 * The closed set of moods a scene may carry.
 *
 * An enum rather than a free string because "mood is one of a known set" is a
 * schema guarantee the contract makes (docs/SCRIPT-STRUCTURER.md §2), and a
 * guarantee expressed as a type cannot drift. The shot prompt interpolates this
 * (FR-8), so an arbitrary string would reach a paid render unchecked.
 */
enum SceneMood: string
{
    case Neutral = 'neutral';
    case Tense = 'tense';
    case Joyful = 'joyful';
    case Somber = 'somber';
    case Wondrous = 'wondrous';
    case Triumphant = 'triumphant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mood) => $mood->value, self::cases());
    }

    /**
     * Trigger words, in English. Scoring lives in the detector; this is data.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Neutral => [],
            self::Tense => ['danger', 'fear', 'afraid', 'chase', 'run', 'threat', 'dark', 'storm', 'scream', 'attack', 'trap'],
            self::Joyful => ['laugh', 'joy', 'happy', 'celebrate', 'dance', 'feast', 'smile', 'song', 'sing', 'wedding'],
            self::Somber => ['died', 'death', 'grief', 'mourn', 'tears', 'cry', 'lost', 'alone', 'silent', 'grave'],
            self::Wondrous => ['magic', 'spirit', 'glow', 'shimmer', 'miracle', 'ancient', 'vision', 'dream', 'strange'],
            self::Triumphant => ['victory', 'won', 'hero', 'saved', 'return', 'crowned', 'freedom', 'peace'],
        };
    }
}
