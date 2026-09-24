<?php

namespace App\Integrations\Local;

use App\Contracts\Data\CharacterDraft;
use App\Contracts\Data\SceneDraft;
use App\Contracts\Data\ScriptBreakdown;
use App\Contracts\ScriptStructurer;
use App\Enums\SceneMood;
use App\Exceptions\InvalidScriptException;
use App\Services\Script\CastDetector;
use App\Services\Script\SceneSplitter;
use App\Services\Script\ScriptBreakdownValidator;
use App\Services\Script\ScriptSegment;

/**
 * Stage 1: script → scenes + cast, deterministically and locally
 * (FR-1, FR-3, FR-4). Contract: docs/SCRIPT-STRUCTURER.md.
 *
 * No LLM, no network, no key, no cost. That is a deliberate v1 choice rather
 * than a placeholder, and it rests on one fact about this product: the owner
 * reviews and edits the breakdown before a single paid render (FR-3). A first
 * pass only has to be a useful starting point, and a deterministic one is
 * reproducible in tests, instant, free, and cannot be prompt-injected by the
 * script it is parsing (§14).
 *
 * `ScriptStructurer` stays an interface so an LLM driver is a config line when
 * the quality ceiling starts to bind. §7 of the contract lists exactly where it
 * would help.
 *
 * The split of responsibility here is what makes the contract enforceable:
 * SceneSplitter and CastDetector are heuristics, ScriptBreakdownValidator is
 * the schema, and the validator runs on this class's own output before it
 * returns — so a heuristic going wrong fails at its own boundary instead of
 * handing the pipeline a scene with no narration.
 */
class HeuristicScriptStructurer implements ScriptStructurer
{
    public function __construct(
        protected SceneSplitter $splitter,
        protected CastDetector $cast,
        protected ScriptBreakdownValidator $validator,
    ) {}

    public function structure(string $script, string $language = 'en'): ScriptBreakdown
    {
        $this->guardInput($script);

        $maxScenes = (int) config('studio.limits.max_scenes', 40);
        $found = $this->splitter->split($script);
        $segments = $this->splitter->capTo($found, $maxScenes);

        if ($segments === []) {
            // Reachable only if the script is punctuation the guard let through.
            throw InvalidScriptException::noProse();
        }

        $names = $this->cast->detect($segments);
        $warnings = [];

        $scenes = [];

        foreach ($segments as $index => $segment) {
            $scenes[] = new SceneDraft(
                setting: $this->settingFor($segment, $index + 1),
                narration: $segment->narration,
                mood: $this->moodFor($segment->narration),
                action: $segment->action,
                characterNames: $this->cast->namesIn($segment, $names),
                sfxCue: $this->sfxCueFor($segment->narration),
            );
        }

        $characters = array_map(
            fn (string $name) => new CharacterDraft(
                name: $name,
                description: $this->cast->describe($name, $script),
            ),
            $names,
        );

        // Warnings exist so a heuristic's blind spot is visible before anything
        // is paid for, rather than discovered in the finished video.
        if ($names === []) {
            $warnings[] = 'No characters were detected, so every shot will render from its '.
                'prompt alone and character consistency does not apply (PRD G2). Add '.
                'characters by hand if the script has a cast.';
        }

        if (count($names) === CastDetector::MAX_CAST) {
            $warnings[] = sprintf(
                'The cast hit the limit of %d, so some detected names were dropped and '.
                'some of those kept may not be people at all. Review the list before '.
                'generating reference images.',
                CastDetector::MAX_CAST,
            );
        }

        if (count($found) > count($segments)) {
            $warnings[] = sprintf(
                'The script produced %d scenes and the limit is %d, so the last %d were '.
                'merged into the final scene rather than discarded. Split it by hand if you '.
                'want that section paced differently.',
                count($found),
                $maxScenes,
                count($found) - $maxScenes + 1,
            );
        }

        return $this->validator->validate(new ScriptBreakdown(
            scenes: $scenes,
            characters: array_values($characters),
            warnings: $warnings,
        ));
    }

    public function providerName(): string
    {
        return 'heuristic';
    }

    /**
     * Refuse what cannot be structured, rather than inventing a scene from it.
     *
     * The old behaviour produced a project containing "Untitled scene" from an
     * empty script: it looked parsed and rendered nothing worth watching.
     */
    protected function guardInput(string $script): void
    {
        if (trim($script) === '') {
            throw InvalidScriptException::empty();
        }

        $limit = (int) config('studio.limits.max_script_characters', 20000);

        if (mb_strlen($script) > $limit) {
            throw InvalidScriptException::tooLong(mb_strlen($script), $limit);
        }

        // Punctuation and digits alone give the narrator nothing to say.
        if (! preg_match('/\p{L}/u', $script)) {
            throw InvalidScriptException::noProse();
        }
    }

    /**
     * An explicit slug line beats inference; inference beats a bare number.
     */
    protected function settingFor(ScriptSegment $segment, int $position): string
    {
        if ($segment->slug !== null) {
            return mb_substr($segment->slug, 0, ScriptBreakdownValidator::MAX_SETTING_LENGTH);
        }

        $haystack = mb_strtolower($segment->narration.' '.($segment->action ?? ''));

        foreach (self::SETTING_KEYWORDS as $setting => $keywords) {
            if ($this->countKeywords($haystack, $keywords) > 0) {
                return $setting;
            }
        }

        return "Scene {$position}";
    }

    /**
     * The ambience this scene's prose actually names, or null.
     *
     * Deliberately unlike settingFor(), which always returns something: that
     * falls back to "Scene 3" because a scene must have a setting to render at
     * all. A cue must NOT fall back, because every cue is a paid request
     * ($0.0194 an effect) and a cue nobody asked for is a sound that does not
     * belong in the video — worse than silence, because someone has to notice it
     * and ask for the render again.
     *
     * The map is narrow on purpose. Every entry is SUSTAINED ambience that can
     * sit under a whole scene, because that is what the v1 mix does: one effect
     * per scene, laid at the scene's offset and looped to cover it. One-shots
     * placed at a moment inside a scene ("the door slams at 0:14") need
     * positioning within the scene rather than at it, and that is Phase 2.
     *
     * Highest score wins rather than first match, so a scene that mentions both
     * rain and a market gets whichever the prose leans on. Ties go to map order,
     * which makes the result stable across runs (NFR-5).
     */
    protected function sfxCueFor(string $narration): ?string
    {
        $lower = mb_strtolower($narration);
        $best = null;
        $bestScore = 0;

        foreach (self::SFX_CUES as $cue => $keywords) {
            $score = $this->countKeywords($lower, $keywords);

            if ($score > $bestScore) {
                $best = $cue;
                $bestScore = $score;
            }
        }

        return $best;
    }

    protected function moodFor(string $narration): SceneMood
    {
        $lower = mb_strtolower($narration);
        $best = SceneMood::Neutral;
        $bestScore = 0;

        foreach (SceneMood::cases() as $mood) {
            $score = $this->countKeywords($lower, $mood->keywords());

            if ($score > $bestScore) {
                $best = $mood;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Whole-word keyword matching.
     *
     * Substring matching looked equivalent and was not: "sing" matched inside
     * "guessing", which labelled a sentence about a cast list as joyful. A
     * mislabelled mood reaches the shot prompt and the paid render (FR-8).
     *
     * @param  list<string>  $keywords
     */
    protected function countKeywords(string $haystack, array $keywords): int
    {
        if ($keywords === []) {
            return 0;
        }

        $pattern = '/\b('.implode('|', array_map(
            fn (string $k) => preg_quote($k, '/'),
            $keywords,
        )).')\b/iu';

        return preg_match_all($pattern, $haystack);
    }

    /** @var array<string, list<string>> setting label => trigger words */
    /**
     * Ambience cue => the words that justify it.
     *
     * Every value is a phrase written to be sent to a sound-effect model as-is,
     * and every one describes a CONTINUOUS sound. Adding a one-shot here (a
     * gunshot, a slammed door) would buy 22 seconds of it looped under the
     * narration, which is the woodpecker failure — see sfxCueFor().
     *
     * @var array<string, list<string>>
     */
    protected const SFX_CUES = [
        'steady rainfall with distant thunder' => ['rain', 'rainfall', 'downpour', 'storm', 'thunder'],
        'a busy open-air market, overlapping voices and haggling' => ['market', 'marketplace', 'traders', 'stalls'],
        'a flowing river with birdsong' => ['river', 'stream', 'waterfall', 'brook'],
        'wind moving through tall trees' => ['forest', 'woods', 'jungle'],
        'ocean waves breaking on a shore' => ['sea', 'ocean', 'waves', 'shore'],
        'night insects and distant frogs' => ['crickets', 'insects', 'frogs'],
        'a crackling open fire' => ['fire', 'flames', 'campfire', 'bonfire', 'embers'],
        'city traffic with distant horns' => ['traffic', 'horns', 'lorries', 'highway'],
        'a crowd of people talking in the open air' => ['crowd', 'crowds', 'gathering'],
        'hand drums and clapping at a celebration' => ['drums', 'drumming', 'dancing', 'festival'],
        'steady rain on a tin roof' => ['tin roof'],
    ];

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
}
