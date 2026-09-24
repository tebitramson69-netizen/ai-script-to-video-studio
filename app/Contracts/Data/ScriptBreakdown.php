<?php

namespace App\Contracts\Data;

/**
 * The structured result of parsing a raw script (FR-1 → FR-3, FR-4).
 */
readonly class ScriptBreakdown
{
    /**
     * @param  list<SceneDraft>  $scenes
     * @param  list<CharacterDraft>  $characters
     * @param  list<string>  $warnings  non-fatal notes for the owner: a cast that hit the cap, scenes merged to fit the limit, no characters detected. Surfaced in the UI so a heuristic's blind spot is visible before anything is paid for, rather than discovered in the finished video.
     */
    public function __construct(
        public array $scenes,
        public array $characters = [],
        public array $warnings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function characterNames(): array
    {
        return array_map(fn (CharacterDraft $c) => $c->name, $this->characters);
    }
}
