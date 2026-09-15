<?php

namespace App\Enums;

/**
 * The project state machine from PRD §8.
 *
 * The order of the cases IS the pipeline order — `rank()` relies on it, and so
 * does every "have we reached stage X yet?" check in the app. Do not reorder
 * without reading `rank()` first.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Scripting = 'scripting';
    case ScriptReady = 'script_ready';
    case CharactersReady = 'characters_ready';
    case ScenesReady = 'scenes_ready';
    case ShotsReady = 'shots_ready';
    case VoiceReady = 'voice_ready';
    case ExportReady = 'export_ready';

    /**
     * Position in the pipeline. Higher means further along.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scripting => 'Parsing script',
            self::ScriptReady => 'Scene list ready',
            self::CharactersReady => 'Characters locked',
            self::ScenesReady => 'Shots planned',
            self::ShotsReady => 'Clips rendered',
            self::VoiceReady => 'Audio ready',
            self::ExportReady => 'Export ready',
        };
    }

    /**
     * What the owner is expected to do next at this stage. Drives the UI's
     * primary action button.
     */
    public function nextAction(): ?string
    {
        return match ($this) {
            self::Draft => 'Parse the script into scenes',
            self::Scripting => null,
            self::ScriptReady => 'Review scenes, then lock character references',
            self::CharactersReady => 'Plan shots',
            self::ScenesReady => 'Render shots',
            self::ShotsReady => 'Generate narration and music',
            self::VoiceReady => 'Assemble and export',
            self::ExportReady => 'Download the video',
        };
    }
}
