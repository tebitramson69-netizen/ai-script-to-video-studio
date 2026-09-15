<?php

namespace App\Contracts\Data;

/**
 * One scene as the structurer understood it, before the owner edits it (FR-3).
 */
readonly class SceneDraft
{
    /**
     * @param  list<string>  $characterNames  names appearing in this scene; must match CharacterDraft names
     */
    public function __construct(
        public string $setting,
        public string $narration,
        public ?string $mood = null,
        public ?string $action = null,
        public array $characterNames = [],
    ) {}
}
