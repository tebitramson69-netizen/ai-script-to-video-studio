<?php

namespace Tests\Feature;

use App\Contracts\Data\ScriptBreakdown;
use App\Contracts\ScriptStructurer;
use App\Enums\SceneMood;
use App\Exceptions\InvalidScriptException;
use App\Integrations\Local\HeuristicScriptStructurer;
use App\Services\Script\ScriptBreakdownValidator;
use Tests\TestCase;

/**
 * The structurer end to end, against the fixtures in tests/Fixtures/scripts.
 *
 * The contract is docs/SCRIPT-STRUCTURER.md. These tests are split to match it:
 * guarantees are asserted exactly, heuristics are asserted only as far as the
 * contract promises. Where a fixture shows the heuristic being imperfect, the
 * test says so rather than pretending otherwise — that is the honest record of
 * what v1 does, and the reason the owner reviews the breakdown (FR-3).
 */
class HeuristicScriptStructurerTest extends TestCase
{
    protected function structurer(): ScriptStructurer
    {
        return app(ScriptStructurer::class);
    }

    protected function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/scripts/{$name}.txt"));
    }

    protected function structure(string $name): ScriptBreakdown
    {
        return $this->structurer()->structure($this->fixture($name));
    }

    // ---- Wiring ------------------------------------------------------------

    public function test_the_default_driver_is_the_real_deterministic_one(): void
    {
        // The only capability whose default is a real implementation: structuring
        // needs no provider, so there is nothing to wait for.
        $this->assertInstanceOf(HeuristicScriptStructurer::class, $this->structurer());
        $this->assertSame('heuristic', $this->structurer()->providerName());
    }

    // ---- Guarantees, on every fixture --------------------------------------

    /**
     * Whatever the input, the schema holds. The validator runs inside the
     * structurer, so this asserts the contract rather than re-deriving it.
     */
    public function test_every_fixture_satisfies_the_schema(): void
    {
        $fixtures = [
            'prose-folk-tale', 'screenplay-dialogue', 'single-block',
            'no-characters', 'ambiguous-capitals', 'scene-markers',
        ];

        foreach ($fixtures as $name) {
            $breakdown = $this->structure($name);
            $cast = $breakdown->characterNames();

            $this->assertNotEmpty($breakdown->scenes, $name);
            $this->assertLessThanOrEqual(
                config('studio.limits.max_scenes'),
                count($breakdown->scenes),
                $name,
            );
            $this->assertLessThanOrEqual(ScriptBreakdownValidator::MAX_CHARACTERS, count($cast), $name);
            $this->assertSame(array_unique($cast), $cast, "{$name}: duplicate character");

            foreach ($breakdown->scenes as $i => $scene) {
                $where = "{$name} scene ".($i + 1);

                $this->assertNotSame('', trim($scene->setting), $where);
                $this->assertNotSame('', trim($scene->narration), $where);
                $this->assertSame(trim($scene->narration), $scene->narration, $where);
                $this->assertDoesNotMatchRegularExpression('/\n\s*\n/', $scene->narration, $where);
                $this->assertInstanceOf(SceneMood::class, $scene->mood, $where);

                foreach ($scene->characterNames as $attributed) {
                    $this->assertContains($attributed, $cast, $where);
                }
            }
        }
    }

    public function test_the_same_script_always_produces_the_same_breakdown(): void
    {
        // Determinism is the whole justification for not using an LLM in v1.
        $this->assertEquals(
            $this->structure('screenplay-dialogue'),
            $this->structure('screenplay-dialogue'),
        );
    }

    // ---- Prose ------------------------------------------------------------

    public function test_a_prose_folk_tale_splits_on_its_paragraphs(): void
    {
        $breakdown = $this->structure('prose-folk-tale');

        $this->assertCount(4, $breakdown->scenes);
        $this->assertSame(['Ada'], $breakdown->characterNames());
        $this->assertSame([], $breakdown->warnings);
    }

    public function test_narration_is_the_authors_words_unchanged(): void
    {
        $scene = $this->structure('prose-folk-tale')->scenes[0];

        // v1 does not rewrite the script. Whatever the heuristics label around
        // it, the text the voice reads is the author's.
        $this->assertStringContainsString('there lived a girl named Ada', $scene->narration);
        $this->assertStringContainsString('the red cloth she always wore', $scene->narration);
    }

    public function test_a_character_description_describes_them_rather_than_quoting_the_script(): void
    {
        $ada = $this->structure('prose-folk-tale')->characters[0];

        $this->assertSame('Ada', $ada->name);

        // "there lived a girl named Ada" -> "a girl". The description feeds the
        // reference prompt directly, so it has to be a phrase about the person,
        // not the sentence they happen to appear in. It used to be the latter,
        // which asked the image model for a village and a river.
        $this->assertSame('a girl', $ada->description);
        $this->assertStringNotContainsString('village', (string) $ada->description);
    }

    // ---- Screenplay and dialogue ------------------------------------------

    public function test_dialogue_cues_are_stripped_from_narration(): void
    {
        foreach ($this->structure('screenplay-dialogue')->scenes as $scene) {
            // One voice narrates everything (FR-14), so a surviving "ADA:" is
            // read aloud as "Ada colon".
            $this->assertStringNotContainsString('ADA:', $scene->narration);
            $this->assertStringNotContainsString('KOFI:', $scene->narration);
        }
    }

    public function test_the_spoken_words_survive_the_cue_being_stripped(): void
    {
        $first = $this->structure('screenplay-dialogue')->scenes[0];

        $this->assertSame('Where is the boat, Kofi? I moved it before the storm. You should have told me.', $first->narration);
    }

    public function test_a_slug_line_becomes_the_setting_of_the_scene_that_follows_it(): void
    {
        $scenes = $this->structure('screenplay-dialogue')->scenes;

        // The kitchen scene is the kitchen. A slug separated from its scene by a
        // blank line used to attach to the scene before it.
        $this->assertSame('KITCHEN — NIGHT', $scenes[0]->setting);
        $this->assertSame('RIVERBANK — DAWN', $scenes[1]->setting);
    }

    public function test_a_slug_governs_every_scene_until_the_next_slug(): void
    {
        $scenes = $this->structure('screenplay-dialogue')->scenes;

        $this->assertSame('RIVERBANK — DAWN', $scenes[2]->setting);
    }

    public function test_a_parenthetical_becomes_action_rather_than_narration(): void
    {
        $first = $this->structure('screenplay-dialogue')->scenes[0];

        $this->assertSame('quietly', $first->action);
        $this->assertStringNotContainsString('quietly', $first->narration);
    }

    /**
     * The attribution that the character_scene pivot exists to carry.
     */
    public function test_a_speaker_is_attributed_even_though_their_name_is_gone_from_the_narration(): void
    {
        $first = $this->structure('screenplay-dialogue')->scenes[0];

        $this->assertContains('Ada', $first->characterNames);
        $this->assertStringNotContainsString('Ada', $first->narration);
    }

    // ---- Edge cases --------------------------------------------------------

    public function test_one_unbroken_block_still_yields_scenes(): void
    {
        $breakdown = $this->structure('single-block');

        $this->assertGreaterThan(1, count($breakdown->scenes));

        foreach ($breakdown->scenes as $scene) {
            $this->assertMatchesRegularExpression('/[.!?]$/', $scene->narration);
        }
    }

    public function test_explicit_scene_markers_and_rules_both_split(): void
    {
        $breakdown = $this->structure('scene-markers');

        $this->assertCount(3, $breakdown->scenes);
        $this->assertSame('The village wakes.', $breakdown->scenes[0]->narration);
        $this->assertSame('The river rises.', $breakdown->scenes[2]->narration);
    }

    public function test_a_script_with_no_cast_is_valid_and_says_so(): void
    {
        $breakdown = $this->structure('no-characters');

        // A narration-only explainer legitimately has no cast, and G2 character
        // consistency simply does not apply. That is a warning, not an error.
        $this->assertSame([], $breakdown->characterNames());
        $this->assertNotEmpty($breakdown->warnings);
        $this->assertStringContainsString('No characters were detected', $breakdown->warnings[0]);
    }

    /**
     * The honest record of a heuristic limit: a town that appears mid-sentence
     * outside a preposition still reads as a name. The owner deletes it (FR-4).
     */
    public function test_ambiguous_capitalisation_finds_the_protagonist_and_may_over_collect(): void
    {
        $cast = $this->structure('ambiguous-capitals')->characterNames();

        // What matters: the protagonist is found, and found first.
        $this->assertSame('Ada', $cast[0]);
        $this->assertContains('Kofi', $cast);

        // A place after a location preposition is excluded.
        $this->assertNotContains('Bafoussam', $cast);
        $this->assertNotContains('Douala', $cast);
    }

    public function test_the_scene_cap_merges_rather_than_discarding_and_warns(): void
    {
        config()->set('studio.limits.max_scenes', 3);

        $script = implode("\n\n", array_map(fn (int $i) => "Body of scene number {$i}.", range(1, 8)));
        $breakdown = $this->structurer()->structure($script);

        $this->assertCount(3, $breakdown->scenes);

        $joined = implode(' ', array_map(fn ($s) => $s->narration, $breakdown->scenes));

        foreach (range(1, 8) as $i) {
            $this->assertStringContainsString("Body of scene number {$i}.", $joined);
        }

        $this->assertNotEmpty($breakdown->warnings);
        $this->assertStringContainsString('merged into the final scene', implode(' ', $breakdown->warnings));
    }

    // ---- Rejected input ----------------------------------------------------

    public function test_an_empty_script_is_refused(): void
    {
        // The old behaviour invented a scene called "Untitled scene": the project
        // looked parsed and rendered nothing worth watching.
        $this->expectException(InvalidScriptException::class);
        $this->expectExceptionMessageMatches('/empty/i');

        $this->structurer()->structure($this->fixture('whitespace-only'));
    }

    public function test_a_script_of_punctuation_alone_is_refused(): void
    {
        $this->expectException(InvalidScriptException::class);
        $this->expectExceptionMessageMatches('/no words/i');

        $this->structurer()->structure($this->fixture('punctuation-only'));
    }

    public function test_a_script_over_the_character_limit_is_refused_and_names_the_limit(): void
    {
        config()->set('studio.limits.max_script_characters', 50);

        $this->expectException(InvalidScriptException::class);
        $this->expectExceptionMessageMatches('/the limit is 50/i');

        $this->structurer()->structure(str_repeat('The village wakes. ', 20));
    }
}
