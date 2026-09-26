<?php

namespace Tests\Unit;

use App\Services\Script\CastDetector;
use App\Services\Script\ScriptSegment;
use PHPUnit\Framework\TestCase;

/**
 * Cast detection (docs/SCRIPT-STRUCTURER.md §5).
 *
 * This is the most heuristic part of the structurer and the tests say so: they
 * pin the signals and the guard rails, not an aspiration that every name is
 * always right. The owner edits the cast (FR-4), and the reason that checkpoint
 * exists is visible here.
 */
class CastDetectorTest extends TestCase
{
    protected CastDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CastDetector;
    }

    /**
     * @param  list<string>  $speakers
     */
    protected function segment(string $narration, array $speakers = []): ScriptSegment
    {
        return new ScriptSegment(narration: $narration, speakers: $speakers);
    }

    public function test_a_dialogue_cue_outweighs_prose_capitalisation(): void
    {
        // A writer who typed "ADA:" has told us Ada is a character. Nothing
        // inferred should outrank being told.
        $cast = $this->detector->detect([
            $this->segment('The road to town passed Mbeng and Mbeng again. Mbeng was long.', ['ADA']),
        ]);

        $this->assertSame('Ada', $cast[0]);
    }

    public function test_names_are_canonicalised_so_a_cue_and_prose_are_one_character(): void
    {
        $cast = $this->detector->detect([
            $this->segment('Then ADA turned away. The boy Kofi watched Ada go.', ['ada']),
        ]);

        // ADA the cue, ADA in prose and Ada in prose are one character, not three.
        // Kofi is mid-sentence here deliberately: a name appearing once and only
        // at a sentence start is below the recurrence threshold, which the next
        // two tests cover.
        $this->assertSame(['Ada', 'Kofi'], $cast);
    }

    public function test_grammar_words_are_not_cast_members(): void
    {
        $cast = $this->detector->detect([
            $this->segment('She left. Then They arrived. But It rained. And Monday came.'),
        ]);

        $this->assertSame([], $cast);
    }

    /**
     * The rule that kept two town names out of the cast of a script whose actual
     * characters are Ada and Kofi.
     */
    public function test_a_capitalised_word_after_a_location_preposition_is_a_place_not_a_person(): void
    {
        $cast = $this->detector->detect([
            $this->segment('They walked to Bamenda. They rested at Bafoussam. They came from Douala.'),
        ]);

        $this->assertSame([], $cast);
    }

    /**
     * A protagonist can legitimately open every sentence they appear in. Scoring
     * only mid-sentence capitalisation left the towns in the cast and Ada out.
     */
    public function test_a_name_that_recurs_at_sentence_starts_is_a_subject(): void
    {
        $cast = $this->detector->detect([
            $this->segment('Ada carried the basket. Ada counted the coins twice.'),
        ]);

        $this->assertSame(['Ada'], $cast);
    }

    public function test_a_single_capitalised_opening_word_is_just_a_sentence(): void
    {
        // "Water moves through three states" must not cast Water.
        $cast = $this->detector->detect([
            $this->segment('Water moves through three states. Ice melts. Vapour rises.'),
        ]);

        $this->assertSame([], $cast);
    }

    public function test_the_cast_is_capped_because_every_character_costs_reference_images(): void
    {
        $segments = array_map(
            fn (string $name) => $this->segment('They spoke.', [$name]),
            ['Ada', 'Kofi', 'Bih', 'Nkeng', 'Tabi', 'Manka', 'Eyong', 'Ngwa'],
        );

        $this->assertCount(CastDetector::MAX_CAST, $this->detector->detect($segments));
    }

    public function test_equal_scores_break_alphabetically_so_output_is_stable(): void
    {
        $segments = [
            $this->segment('They spoke.', ['Zita']),
            $this->segment('They spoke.', ['Ada']),
            $this->segment('They spoke.', ['Manka']),
        ];

        // Insertion order would be Zita, Ada, Manka. A deterministic structurer
        // has to be orderly too, or fixtures cannot assert exact output.
        $this->assertSame(['Ada', 'Manka', 'Zita'], $this->detector->detect($segments));
    }

    /**
     * The attribution rule the whole pivot table exists for.
     */
    public function test_a_cue_only_speaker_is_attributed_even_though_the_name_is_gone_from_the_narration(): void
    {
        // This is what a stripped cue looks like downstream: Ada spoke, but the
        // word "Ada" is nowhere in the text a search could scan.
        $segment = $this->segment('Where is the boat?', ['ADA']);

        $this->assertSame(['Ada'], $this->detector->namesIn($segment, ['Ada', 'Kofi']));
    }

    public function test_a_name_mentioned_in_prose_is_attributed_without_a_cue(): void
    {
        $segment = $this->segment('Kofi points at the far shore.');

        $this->assertSame(['Kofi'], $this->detector->namesIn($segment, ['Ada', 'Kofi']));
    }

    public function test_attribution_never_invents_a_name_outside_the_cast(): void
    {
        $segment = $this->segment('Bih waited.', ['BIH']);

        // Referential integrity: the validator refuses a scene naming a character
        // the breakdown does not have, so attribution must filter to the cast.
        $this->assertSame([], $this->detector->namesIn($segment, ['Ada']));
    }

    public function test_a_description_is_the_phrase_that_describes_them_not_the_sentence_they_appear_in(): void
    {
        // The defect this replaced: describe() returned the whole first sentence
        // containing the name, so a character reference was requested as
        // "Character reference portrait of Ada. Thunder rolled somewhere behind
        // the hills..." — and an image model given that renders weather.
        $script = 'Thunder rolled behind the hills, and Ada lay awake counting the drops.';

        $this->assertNull(
            $this->detector->describe('Ada', $script),
            'A narrative sentence is not a description and must never be sent as one.',
        );
    }

    public function test_an_appositive_is_a_description(): void
    {
        $this->assertSame(
            'a young trader',
            $this->detector->describe('Ada', 'Ada, a young trader, walked to the market before dawn.'),
        );
    }

    public function test_a_copula_is_a_description(): void
    {
        $this->assertSame(
            'a tall woman with a steady voice',
            $this->detector->describe('Ada', 'Ada was a tall woman with a steady voice.'),
        );
    }

    public function test_a_role_before_the_name_is_a_description(): void
    {
        $this->assertSame('The boy', $this->detector->describe('Kofi', 'The boy Kofi watched the river rise.'));
        $this->assertSame('Her brother', $this->detector->describe('Kofi', 'Her brother Kofi never waited.'));
    }

    public function test_a_phrase_that_names_no_person_is_refused(): void
    {
        // The gate the whole method rests on. Without it the copula pattern
        // returns "a long way from home", which reads like a description,
        // survives every other check, and is rendered at full price.
        $this->assertNull(
            $this->detector->describe('Ada', 'Ada was a long way from home and the road was dark.'),
        );
    }

    public function test_a_character_with_no_descriptive_phrase_has_no_description(): void
    {
        // Null rather than an invented sentence: the owner writes it before any
        // reference image is paid for, and the prompt omits it cleanly.
        $this->assertNull($this->detector->describe('Ada', 'Ada counted the coins twice.'));
        $this->assertNull($this->detector->describe('Ada', 'Nobody is named here.'));
    }
}
