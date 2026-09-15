<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\CharacterDraft;
use App\Contracts\Data\SceneDraft;
use App\Contracts\Data\ScriptBreakdown;
use App\Contracts\ScriptStructurer;

/**
 * Rule-based script structuring — no LLM, no network, no cost.
 *
 * This is a real implementation, not a stub. An LLM produces a better breakdown,
 * but the owner edits the scene list anyway (FR-3), so a deterministic parser is
 * a legitimate default: it is free, instant, reproducible in tests, and it can
 * never be prompt-injected by the script it is parsing (§14).
 *
 * Swap in an LLM driver by adding a class to config/studio.php.
 */
class FakeScriptStructurer implements ScriptStructurer
{
    /** Roughly the length of a comfortable narrated scene. */
    protected const TARGET_WORDS_PER_SCENE = 35;

    protected const MAX_WORDS_PER_SCENE = 60;

    /**
     * Capitalised words that are not character names. Without this the parser
     * reports "The" and "Then" as cast members.
     *
     * @var list<string>
     */
    protected const NOT_NAMES = [
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

    /** @var array<string, list<string>> mood => trigger words */
    protected const MOOD_KEYWORDS = [
        'tense' => ['danger', 'fear', 'afraid', 'chase', 'run', 'threat', 'dark', 'storm', 'scream', 'attack', 'trap'],
        'joyful' => ['laugh', 'joy', 'happy', 'celebrate', 'dance', 'feast', 'smile', 'song', 'sing', 'wedding'],
        'somber' => ['died', 'death', 'grief', 'mourn', 'tears', 'cry', 'lost', 'alone', 'silent', 'grave'],
        'wondrous' => ['magic', 'spirit', 'glow', 'shimmer', 'miracle', 'ancient', 'vision', 'dream', 'strange'],
        'triumphant' => ['victory', 'won', 'hero', 'saved', 'return', 'crowned', 'freedom', 'peace'],
    ];

    /** @var array<string, list<string>> setting label => trigger words */
    protected const SETTING_KEYWORDS = [
        'a dense forest' => ['forest', 'trees', 'woods', 'jungle', 'bush'],
        'a village' => ['village', 'compound', 'hut', 'huts', 'settlement'],
        'a busy market' => ['market', 'stall', 'traders', 'marketplace'],
        'a riverbank' => ['river', 'stream', 'water', 'lake', 'waterfall'],
        'a mountainside' => ['mountain', 'hill', 'cliff', 'peak', 'valley'],
        'a city street' => ['city', 'street', 'road', 'town', 'traffic'],
        'an interior room' => ['room', 'house', 'kitchen', 'inside', 'indoors'],
        'a farm field' => ['farm', 'field', 'crops', 'harvest', 'plantation'],
        'a night sky' => ['night', 'moon', 'stars', 'midnight', 'darkness'],
        'a school' => ['school', 'classroom', 'teacher', 'pupils', 'students'],
    ];

    public function structure(string $script, string $language = 'en'): ScriptBreakdown
    {
        $script = $this->normalise($script);

        $blocks = $this->splitIntoBlocks($script);
        $maxScenes = (int) config('studio.limits.max_scenes', 40);

        $names = $this->detectCharacterNames($script);

        $scenes = [];
        foreach ($blocks as $block) {
            if (count($scenes) >= $maxScenes) {
                break;
            }

            $scenes[] = new SceneDraft(
                setting: $this->inferSetting($block, count($scenes) + 1),
                narration: $block,
                mood: $this->inferMood($block),
                action: null,
                characterNames: $this->namesPresentIn($block, $names),
            );
        }

        // A script with no blank lines and one sentence still has to yield one
        // scene, otherwise the project has nothing to render.
        if ($scenes === []) {
            $scenes[] = new SceneDraft(
                setting: 'Scene 1',
                narration: $script !== '' ? $script : 'Untitled scene.',
                mood: 'neutral',
                characterNames: [],
            );
        }

        $characters = array_map(
            fn (string $name) => new CharacterDraft(
                name: $name,
                description: $this->describeCharacter($name, $script),
            ),
            $names,
        );

        return new ScriptBreakdown(scenes: $scenes, characters: array_values($characters));
    }

    public function providerName(): string
    {
        return 'fake';
    }

    protected function normalise(string $script): string
    {
        $script = str_replace(["\r\n", "\r"], "\n", $script);
        $script = preg_replace('/[ \t]+/', ' ', $script) ?? $script;
        $script = preg_replace('/\n{3,}/', "\n\n", $script) ?? $script;

        return trim($script);
    }

    /**
     * Paragraphs first — a writer's blank lines are the best scene signal we
     * have. Any paragraph longer than MAX_WORDS_PER_SCENE is then split on
     * sentence boundaries so no single scene carries more narration than a clip
     * can reasonably cover.
     *
     * @return list<string>
     */
    protected function splitIntoBlocks(string $script): array
    {
        if ($script === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $script) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), fn ($p) => $p !== ''));

        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            if (str_word_count($paragraph) <= self::MAX_WORDS_PER_SCENE) {
                $blocks[] = $paragraph;

                continue;
            }

            foreach ($this->splitBySentences($paragraph) as $chunk) {
                $blocks[] = $chunk;
            }
        }

        return $blocks;
    }

    /**
     * Group sentences into chunks of about TARGET_WORDS_PER_SCENE words, never
     * breaking mid-sentence — narration that stops mid-sentence sounds broken no
     * matter how good the voice model is.
     *
     * @return list<string>
     */
    protected function splitBySentences(string $text): array
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
     * Two signals, combined:
     *   1. Screenplay dialogue cues — a line that is "NAME:" or "NAME —".
     *   2. Capitalised words appearing somewhere other than the start of a
     *      sentence, which in English prose is a strong proper-noun signal.
     *
     * @return list<string>
     */
    protected function detectCharacterNames(string $script): array
    {
        $counts = [];

        // Signal 1: dialogue cues.
        if (preg_match_all('/^\s*([\p{Lu}][\p{L}\'-]{1,20})\s*[:—]/mu', $script, $matches)) {
            foreach ($matches[1] as $name) {
                $counts[$this->canonicaliseName($name)] = ($counts[$this->canonicaliseName($name)] ?? 0) + 3;
            }
        }

        // Signal 2: mid-sentence capitalisation.
        foreach (preg_split('/(?<=[.!?])\s+|\n/', $script) ?: [] as $sentence) {
            $words = preg_split('/\s+/', trim($sentence)) ?: [];

            // Skip index 0: the first word of a sentence is capitalised by
            // grammar, not because it is a name.
            for ($i = 1, $n = count($words); $i < $n; $i++) {
                $word = trim($words[$i], ".,;:!?\"'()[]—-");

                if (! preg_match('/^\p{Lu}[\p{L}\'-]{2,20}$/u', $word)) {
                    continue;
                }

                $name = $this->canonicaliseName($word);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        foreach (self::NOT_NAMES as $stop) {
            unset($counts[$this->canonicaliseName($stop)]);
        }

        arsort($counts);

        // Keep the cast small. Every extra character is another reference image
        // the owner has to review and pay for (FR-5), and folk tales rarely need
        // more than a handful.
        return array_slice(array_keys($counts), 0, 6);
    }

    protected function canonicaliseName(string $name): string
    {
        return ucfirst(mb_strtolower(trim($name)));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    protected function namesPresentIn(string $block, array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name) => (bool) preg_match('/\b'.preg_quote($name, '/').'\b/iu', $block),
        ));
    }

    protected function inferSetting(string $block, int $index): string
    {
        // Honour an explicit screenplay slug line if the writer supplied one.
        if (preg_match('/^\s*(INT\.|EXT\.)\s*(.+)$/mi', $block, $m)) {
            return trim(mb_substr(trim($m[2]), 0, 200));
        }

        $lower = mb_strtolower($block);

        foreach (self::SETTING_KEYWORDS as $setting => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $setting;
                }
            }
        }

        return "Scene {$index}";
    }

    protected function inferMood(string $block): string
    {
        $lower = mb_strtolower($block);
        $best = 'neutral';
        $bestScore = 0;

        foreach (self::MOOD_KEYWORDS as $mood => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                $score += substr_count($lower, $keyword);
            }

            if ($score > $bestScore) {
                $best = $mood;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * A first-pass physical description, taken from the sentence that introduces
     * the character. The owner edits this before reference images are generated,
     * so it only has to be a useful starting point.
     */
    protected function describeCharacter(string $name, string $script): string
    {
        foreach (preg_split('/(?<=[.!?])\s+/', $script) ?: [] as $sentence) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/iu', $sentence)) {
                return trim(mb_substr(trim($sentence), 0, 300));
            }
        }

        return "{$name}, a character in this story.";
    }
}
