<?php

namespace App\Services\Script;

/**
 * Cuts a script into scenes, and separates what the narrator reads from what it
 * must not (docs/SCRIPT-STRUCTURER.md §3, §4).
 *
 * Deterministic and self-contained: no config beyond the scene cap, no I/O, no
 * randomness. Given the same string it returns the same segments, which is what
 * lets the fixture tests assert exact output rather than shapes.
 */
class SceneSplitter
{
    /** Roughly the length of a comfortable narrated scene. */
    public const TARGET_WORDS_PER_SCENE = 35;

    public const MAX_WORDS_PER_SCENE = 60;

    /** A line that is only `---`, `===` or `***`. */
    protected const RULE = '/^\s*([-=*])\1{2,}\s*$/';

    /** `INT. KITCHEN — DAY`, `EXT ROAD`. */
    protected const SLUG = '/^\s*(INT|EXT)[\s.]+(.*)$/i';

    /** `SCENE 3`, `Scene 3:`, `Scene Three`. */
    protected const SCENE_MARKER = '/^\s*scene\s+[\w-]+\s*[:.]?\s*$/i';

    /**
     * `ADA:` or `ADA —`. Bounded to 28 characters so a sentence containing a
     * colon ("She had one rule: never look back") is not mistaken for a cue.
     */
    protected const CUE = '/^\s*(\p{Lu}[\p{L}\x27 .-]{0,27}?)\s*[:—]\s*(.*)$/u';

    /** A line that is entirely a parenthetical: `(beat)`, `(quietly)`. */
    protected const PARENTHETICAL = '/^\s*\((.+)\)\s*$/';

    /**
     * Every scene the script yields, uncapped.
     *
     * Capping is a separate call so the caller can see how many scenes were
     * merged to fit and say so, without splitting the script twice.
     *
     * @return list<ScriptSegment>
     */
    public function split(string $script): array
    {
        $segments = [];
        $pendingAction = null;

        // A slug line governs everything after it until the next slug — that is
        // what INT./EXT. means in a screenplay. Carrying it FORWARD also fixes
        // the direction bug: a slug separated from its scene by a blank line used
        // to attach to the scene BEFORE it, so a kitchen scene came out labelled
        // as the riverbank that followed.
        $currentSlug = null;

        foreach ($this->blocks($this->normalise($script)) as $block) {
            $parsed = $this->parseBlock($block);

            if ($parsed->slug !== null) {
                $currentSlug = $parsed->slug;
            }

            if ($parsed->narration === '') {
                // A slug-only or parenthetical-only block. Its location is now
                // current and its action belongs to the next real scene.
                $pendingAction = $this->joinAction($pendingAction, $parsed->action);

                continue;
            }

            $action = $this->joinAction($pendingAction, $parsed->action);
            $pendingAction = null;

            $chunks = str_word_count($parsed->narration) <= self::MAX_WORDS_PER_SCENE
                ? [$parsed->narration]
                : $this->splitBySentences($parsed->narration);

            foreach ($chunks as $chunk) {
                // Slug, action and speakers belong to the whole block, so every
                // chunk it produced inherits them — the cast does not vanish
                // halfway through a long scene.
                $segments[] = new ScriptSegment(
                    narration: $chunk,
                    action: $action,
                    slug: $currentSlug,
                    speakers: $parsed->speakers,
                );
            }
        }

        // A trailing parenthetical with no scene after it still belongs to the
        // last one rather than being dropped.
        if ($pendingAction !== null && $segments !== []) {
            $last = array_pop($segments);
            $segments[] = new ScriptSegment(
                narration: $last->narration,
                action: $this->joinAction($last->action, $pendingAction),
                slug: $last->slug,
                speakers: $last->speakers,
            );
        }

        return $segments;
    }

    public function normalise(string $script): string
    {
        $script = str_replace(["\r\n", "\r"], "\n", $script);
        $script = preg_replace('/[ \t]+/', ' ', $script) ?? $script;
        $script = preg_replace('/\n{3,}/', "\n\n", $script) ?? $script;

        return trim($script);
    }

    /**
     * Scene-sized chunks of raw text, before cue and parenthetical extraction.
     *
     * Explicit markers beat blank lines, because a writer who typed `INT.` has
     * told us where the scene starts and a blank line is only a guess.
     *
     * @return list<string>
     */
    protected function blocks(string $script): array
    {
        if ($script === '') {
            return [];
        }

        $blocks = [];
        $current = [];

        $flush = function () use (&$blocks, &$current) {
            $text = trim(implode("\n", $current));

            if ($text !== '') {
                $blocks[] = $text;
            }

            $current = [];
        };

        foreach (explode("\n", $script) as $line) {
            if (preg_match(self::RULE, $line)) {
                // A rule is a separator and carries no content of its own.
                $flush();

                continue;
            }

            if (preg_match(self::SLUG, $line) || preg_match(self::SCENE_MARKER, $line)) {
                $flush();
                $current[] = $line;

                continue;
            }

            if (trim($line) === '') {
                $flush();

                continue;
            }

            $current[] = $line;
        }

        $flush();

        return $blocks;
    }

    /**
     * Interpret one raw block: lift out the slug, the cues and the
     * parentheticals, and leave behind only what the narrator reads.
     *
     * Returns a single segment whose narration may be empty — a block that was
     * nothing but a slug line has a location and no words.
     */
    protected function parseBlock(string $block): ScriptSegment
    {
        $slug = null;
        $narrationLines = [];
        $actions = [];
        $speakers = [];

        foreach (explode("\n", $block) as $line) {
            if (preg_match(self::SCENE_MARKER, $line)) {
                // A bare "Scene 3" is a label, not narration and not a location.
                continue;
            }

            if (preg_match(self::SLUG, $line, $m)) {
                $remainder = trim(rtrim(trim($m[2]), '-—'));

                if ($remainder !== '') {
                    $slug ??= $remainder;
                }

                continue;
            }

            if (preg_match(self::PARENTHETICAL, $line, $m)) {
                $actions[] = trim($m[1]);

                continue;
            }

            if (preg_match(self::CUE, $line, $m)) {
                $name = trim($m[1]);
                $speech = trim($m[2]);

                // The cue names the speaker and is then discarded: FR-14 gives
                // the project one voice, so a surviving "ADA:" is read aloud.
                $speakers[] = $name;

                if ($speech !== '') {
                    $narrationLines[] = $speech;
                }

                continue;
            }

            $narrationLines[] = trim($line);
        }

        return new ScriptSegment(
            narration: trim(implode(' ', array_filter($narrationLines, fn ($l) => $l !== ''))),
            action: $actions === [] ? null : implode('. ', array_unique($actions)),
            slug: $slug,
            speakers: array_values(array_unique($speakers)),
        );
    }

    /**
     * Group sentences into chunks of about TARGET_WORDS_PER_SCENE, never
     * breaking mid-sentence — narration that stops mid-sentence sounds broken no
     * matter how good the voice model is.
     *
     * @return list<string>
     */
    public function splitBySentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];

        $chunks = [];
        $current = [];
        $currentWords = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            $words = str_word_count($sentence);

            if ($current !== [] && $currentWords + $words > self::TARGET_WORDS_PER_SCENE) {
                $chunks[] = implode(' ', $current);
                $current = [];
                $currentWords = 0;
            }

            $current[] = $sentence;
            $currentWords += $words;
        }

        if ($current !== []) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }

    /**
     * Bring the count under the cap WITHOUT discarding any of the author's words
     * (contract §3.5).
     *
     * @param  list<ScriptSegment>  $segments
     * @return list<ScriptSegment>
     */
    public function capTo(array $segments, int $maxScenes): array
    {
        if ($maxScenes < 1) {
            $maxScenes = 1;
        }

        if (count($segments) <= $maxScenes) {
            return array_values($segments);
        }

        $kept = array_slice($segments, 0, $maxScenes - 1);
        $tail = array_slice($segments, $maxScenes - 1);

        // Everything past the cap becomes one final scene. Dropping it would
        // silently lose script; ShotPlanner splits a long scene across several
        // shots anyway (FR-17), so a fat final scene still renders.
        $kept[] = new ScriptSegment(
            narration: trim(implode(' ', array_map(fn (ScriptSegment $s) => $s->narration, $tail))),
            action: $this->joinAction(...array_map(fn (ScriptSegment $s) => $s->action, $tail)),
            slug: $tail[0]->slug,
            speakers: array_values(array_unique(array_merge(
                ...array_map(fn (ScriptSegment $s) => $s->speakers, $tail)
            ))),
        );

        return array_values($kept);
    }

    protected function joinAction(?string ...$actions): ?string
    {
        $parts = array_values(array_filter(
            array_map(fn (?string $a) => $a === null ? '' : trim($a), $actions),
            fn (string $a) => $a !== '',
        ));

        return $parts === [] ? null : implode('. ', array_unique($parts));
    }
}
