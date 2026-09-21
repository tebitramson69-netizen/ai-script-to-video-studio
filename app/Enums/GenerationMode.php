<?php

namespace App\Enums;

/**
 * How a clip is conditioned.
 *
 * Kept explicit rather than inferred from "is there a reference image?" because
 * providers expose these as *different endpoints* with different input schemas
 * and often different prices. Collapsing them into one generic call would hide
 * exactly the capability differences the adapter needs to act on.
 */
enum GenerationMode: string
{
    /** Prompt only. Used for shots with no character in them. */
    case TextToVideo = 'text_to_video';

    /**
     * A single starting image plus a motion prompt. This is how FR-6 carries a
     * locked character reference into every shot, and the default for Phase 1.
     */
    case ImageToVideo = 'image_to_video';

    /**
     * Several reference images conditioning one clip, without any of them being
     * the literal first frame. Phase 2 (FR-7, multi-angle character sheets) —
     * modelled now so the adapter surface does not have to change later.
     */
    case ReferenceToVideo = 'reference_to_video';

    public function label(): string
    {
        return match ($this) {
            self::TextToVideo => 'Text to video',
            self::ImageToVideo => 'Image to video',
            self::ReferenceToVideo => 'Reference to video',
        };
    }
}
