<?php

namespace App\Services\Script;

/**
 * One scene's worth of raw script, after splitting but before interpretation.
 *
 * `narration` is what the voice will read: dialogue cue labels removed,
 * parentheticals removed, blank lines gone. `action` is what those removals
 * collected. `slug` is an explicit screenplay location if the writer gave one.
 * `speakers` are the names the cues named — kept separately because those names
 * are no longer in the narration text to be found later.
 */
readonly class ScriptSegment
{
    /**
     * @param  list<string>  $speakers
     */
    public function __construct(
        public string $narration,
        public ?string $action = null,
        public ?string $slug = null,
        public array $speakers = [],
    ) {}
}
