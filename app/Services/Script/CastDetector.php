<?php

namespace App\Services\Script;

/**
 * Finds the cast (docs/SCRIPT-STRUCTURER.md §5).
 *
 * Two signals, summed: a dialogue cue is worth 3, a capitalised word that is not
 * at the start of a sentence is worth 1. English prose capitalises mid-sentence
 * almost only for proper nouns, which makes it a usable signal and an imprecise
 * one — it also catches place names. That is why there is a stop-word list, a
 * cap, and an owner who edits the result (FR-4).
 *
 * The cap is a cost decision rather than a parsing one: every character is three
 * reference images the owner reviews and pays for (FR-5).
 */
class CastDetector
{
    public const MAX_CAST = 6;

    protected const CUE_WEIGHT = 3;

    protected const PROSE_WEIGHT = 1;

    /** How many sentence openings a name needs before it counts as a subject. */
    protected const MIN_OPENING_RECURRENCE = 2;

    /**
     * A capitalised word straight after one of these is a place, not a person.
     * High precision and cheap: it is what kept Bamenda and Bafoussam out of the
     * cast of a script whose actual characters are Ada and Kofi.
     *
     * @var list<string>
     */
    protected const PLACE_PREPOSITIONS = [
        'to', 'at', 'in', 'into', 'from', 'near', 'towards', 'toward',
        'through', 'across', 'beyond', 'outside', 'inside', 'around',
    ];

    /**
     * Capitalised words that are not character names. Without this the cast
     * reports "The" and "Then" as people.
     *
     * @var list<string>
     */
    public const NOT_NAMES = [
        'The', 'A', 'An', 'And', 'But', 'So', 'Then', 'When', 'While', 'After',
        'Before', 'Now', 'Today', 'Tomorrow', 'Yesterday', 'One', 'Two', 'Three',
        'He', 'She', 'They', 'It', 'We', 'You', 'I', 'His', 'Her', 'Their',
        'That', 'This', 'There', 'Here', 'What', 'Who', 'Why', 'How', 'If',
        'God', 'Lord', 'Mr', 'Mrs', 'Ms', 'Dr', 'Sir', 'Madam',
        'Int', 'Ext', 'Interior', 'Exterior', 'Fade', 'Cut', 'Scene', 'Narrator',
        'Once', 'Long', 'Many', 'Every', 'Some', 'All', 'No', 'Yes', 'Not',
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
        'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Names in descending confidence, capped.
     *
     * @param  list<ScriptSegment>  $segments
     * @return list<string>
     */
    public function detect(array $segments): array
    {
        $scores = [];
        $openings = [];

        foreach ($segments as $segment) {
            // Cues first, and scored highest: a writer who typed "ADA:" has told
            // us Ada is a character. Nothing inferred beats being told.
            foreach ($segment->speakers as $speaker) {
                $name = $this->canonicalise($speaker);

                if ($name === '') {
                    continue;
                }

                $scores[$name] = ($scores[$name] ?? 0) + self::CUE_WEIGHT;
            }

            foreach ($this->proseNamesIn($segment->narration) as $name) {
                $scores[$name] = ($scores[$name] ?? 0) + self::PROSE_WEIGHT;
            }

            foreach ($this->openingNamesIn($segment->narration) as $name) {
                $openings[$name] = ($openings[$name] ?? 0) + 1;
            }
        }

        // A name that only ever starts sentences scores nothing from prose
        // capitalisation, because grammar capitalises the first word regardless.
        // But a protagonist can legitimately open every sentence they appear in
        // — "Ada carried the basket." — and without this the cast came back with
        // the towns in it and Ada missing.
        //
        // Guarded by recurrence: one capitalised opening word is just a sentence
        // ("Water moves through three states"), several is a subject.
        foreach ($openings as $name => $count) {
            if ($count >= self::MIN_OPENING_RECURRENCE) {
                $scores[$name] = ($scores[$name] ?? 0) + $count;
            }
        }

        foreach (self::NOT_NAMES as $stop) {
            unset($scores[$this->canonicalise($stop)]);
        }

        // Sort by score, then alphabetically, so two names on equal footing come
        // out in a stable order rather than in PHP's insertion order. The whole
        // point of a deterministic structurer is that the fixtures can assert
        // exact output.
        uksort($scores, function (string $a, string $b) use ($scores) {
            return [$scores[$b], $a] <=> [$scores[$a], $b];
        });

        return array_slice(array_keys($scores), 0, self::MAX_CAST);
    }

    /**
     * Which of the known cast appear in this segment.
     *
     * A cue-named speaker counts even though the cue label has been stripped
     * from the narration — that removal is exactly why a text search alone is
     * not enough.
     *
     * @param  list<string>  $cast
     * @return list<string>
     */
    public function namesIn(ScriptSegment $segment, array $cast): array
    {
        $spoken = array_map($this->canonicalise(...), $segment->speakers);

        return array_values(array_filter($cast, function (string $name) use ($segment, $spoken) {
            if (in_array($name, $spoken, true)) {
                return true;
            }

            return (bool) preg_match('/\b'.preg_quote($name, '/').'\b/iu', $segment->narration);
        }));
    }

    /**
     * A first-pass physical description, taken from the sentence that introduces
     * the character. The owner edits this before any reference image is paid for,
     * so it only has to be a useful starting point.
     */
    public function describe(string $name, string $script): ?string
    {
        foreach (preg_split('/(?<=[.!?])\s+|\n/', $script) ?: [] as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            if (preg_match('/\b'.preg_quote($name, '/').'\b/iu', $sentence)) {
                return mb_substr($sentence, 0, 300);
            }
        }

        return null;
    }

    public function canonicalise(string $name): string
    {
        $name = trim($name, " \t\n\r\0\x0B.,;:!?\"'()[]—-");

        return $name === '' ? '' : ucfirst(mb_strtolower($name));
    }

    /**
     * Capitalised words appearing somewhere other than the start of a sentence.
     *
     * @return list<string>
     */
    protected function proseNamesIn(string $text): array
    {
        $found = [];

        foreach ($this->sentences($text) as $sentence) {
            $words = preg_split('/\s+/', $sentence) ?: [];

            // Index 0 is skipped: the first word of a sentence is capitalised by
            // grammar, not because it names anyone. openingNamesIn() handles it.
            for ($i = 1, $n = count($words); $i < $n; $i++) {
                $word = $this->strip($words[$i]);

                if (! $this->looksLikeName($word)) {
                    continue;
                }

                $previous = mb_strtolower($this->strip($words[$i - 1]));

                if (in_array($previous, self::PLACE_PREPOSITIONS, true)) {
                    continue;
                }

                $found[] = $this->canonicalise($word);
            }
        }

        return $found;
    }

    /**
     * Capitalised first words of sentences, which may be a subject or may just be
     * grammar. Scored only on recurrence — see detect().
     *
     * @return list<string>
     */
    protected function openingNamesIn(string $text): array
    {
        $found = [];

        foreach ($this->sentences($text) as $sentence) {
            $words = preg_split('/\s+/', $sentence) ?: [];
            $word = $this->strip($words[0] ?? '');

            if ($this->looksLikeName($word)) {
                $found[] = $this->canonicalise($word);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    protected function sentences(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/(?<=[.!?])\s+|\n/', $text) ?: []),
            fn (string $s) => $s !== '',
        ));
    }

    protected function looksLikeName(string $word): bool
    {
        return (bool) preg_match('/^\p{Lu}[\p{L}\x27-]{2,20}$/u', $word);
    }

    protected function strip(string $word): string
    {
        return trim($word, ".,;:!?\"'()[]—-");
    }
}
