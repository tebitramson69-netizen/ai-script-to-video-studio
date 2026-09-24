<?php

namespace App\Contracts\Data;

use App\Enums\SceneMood;

/**
 * One scene as the structurer understood it, before the owner edits it (FR-3).
 *
 * The guarantees on these fields are specified in docs/SCRIPT-STRUCTURER.md §2
 * and enforced by ScriptBreakdownValidator. In short: setting and narration are
 * non-empty, narration carries no dialogue cue labels, mood is a known case, and
 * every name in characterNames exists in the breakdown's character list.
 */
readonly class SceneDraft
{
    /**
     * @param  SceneMood  $mood  a known case, never a free string — the shot prompt interpolates it (FR-8)
     * @param  list<string>  $characterNames  AUTHORITATIVE cast attribution for this scene. Persisted to the character_scene pivot and read back by PlanShotsJob rather than re-derived from the narration text, because stripping dialogue cues removes the very name a text search would have matched.
     * @param  string|null  $sfxCue  ambience heard in this scene's own prose (FR-13), or null. Null is the common case and the safe one: every cue is a paid request, so a cue is only set when a closed keyword map matches. Unlike `setting`, this NEVER falls back to a default.
     */
    public function __construct(
        public string $setting,
        public string $narration,
        public SceneMood $mood = SceneMood::Neutral,
        public ?string $action = null,
        public array $characterNames = [],
        public ?string $sfxCue = null,
    ) {}
}
