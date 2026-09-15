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
     */
    public function __construct(
        public array $scenes,
        public array $characters = [],
    ) {}
}
