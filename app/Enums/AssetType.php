<?php

namespace App\Enums;

enum AssetType: string
{
    case CharacterReference = 'character_reference';
    case ShotClip = 'shot_clip';
    case Narration = 'narration';

    /** The ordered concatenation of the per-shot narration segments — the single
     * voiceover that plays over the whole video (Phase 1). Distinct from
     * Narration so that regenerating one shot's audio can never be mistaken for
     * the project's narration track. */
    case NarrationTrack = 'narration_track';
    case Music = 'music';
    case SoundEffect = 'sound_effect';
    case FinalVideo = 'final_video';

    /**
     * Intermediate assets may be purged after a successful export (NFR-7).
     * Final videos and locked character references are always kept.
     */
    public function isPurgeable(): bool
    {
        return $this === self::ShotClip;
    }
}
