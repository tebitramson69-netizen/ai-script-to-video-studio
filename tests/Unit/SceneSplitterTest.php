<?php

namespace Tests\Unit;

use App\Services\Script\SceneSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Scene splitting and narration extraction (docs/SCRIPT-STRUCTURER.md §3, §4).
 *
 * Pure unit tests: the splitter touches no config, no database and no clock, so
 * these assert exact strings rather than shapes. That is the payoff of a
 * deterministic structurer.
 */
class SceneSplitterTest extends TestCase
{
    protected SceneSplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new SceneSplitter;
    }

    /**
     * @return list<string>
     */
    protected function narrations(string $script): array
    {
        return array_map(fn ($s) => $s->narration, $this->splitter->split($script));
    }

    public function test_blank_lines_separate_scenes(): void
    {
        $this->assertSame(
            ['The village wakes.', 'The market fills.'],
            $this->narrations("The village wakes.\n\nThe market fills."),
        );
    }

    public function test_line_endings_and_runs_of_whitespace_are_normalised(): void
    {
        $this->assertSame(
            ['One two.', 'Three.'],
            $this->narrations("One   two.\r\n\r\n\r\n\r\nThree.\t"),
        );
    }

    public function test_a_horizontal_rule_separates_scenes(): void
    {
        $this->assertSame(
            ['Before.', 'After.'],
            $this->narrations("Before.\n---\nAfter."),
        );
    }

    public function test_a_scene_marker_is_a_label_not_narration(): void
    {
        // "Scene 2" must not be read aloud, and must not become the setting.
        $segments = $this->splitter->split("Scene 1\nThe village wakes.\nScene 2\nThe market fills.");

        $this->assertSame(['The village wakes.', 'The market fills.'], array_map(
            fn ($s) => $s->narration,
            $segments,
        ));

        $this->assertNull($segments[0]->slug);
    }

    public function test_a_slug_line_becomes_the_location_and_is_not_narrated(): void
    {
        $segments = $this->splitter->split("INT. KITCHEN — NIGHT\nShe fills the pot.");

        $this->assertCount(1, $segments);
        $this->assertSame('KITCHEN — NIGHT', $segments[0]->slug);
        $this->assertSame('She fills the pot.', $segments[0]->narration);
    }

    /**
     * The bug this test exists for: the slug was separated from its scene by a
     * blank line, so it attached to the scene BEFORE it — a kitchen scene came
     * out labelled as the riverbank that followed.
     */
    public function test_a_slug_governs_what_follows_it_never_what_precedes_it(): void
    {
        $segments = $this->splitter->split(
            "INT. KITCHEN — NIGHT\n\nShe fills the pot.\n\nEXT. RIVERBANK — DAWN\n\nThe water is still."
        );

        $this->assertCount(2, $segments);
        $this->assertSame('KITCHEN — NIGHT', $segments[0]->slug);
        $this->assertSame('RIVERBANK — DAWN', $segments[1]->slug);
    }

    public function test_a_slug_persists_until_the_next_slug(): void
    {
        // Screenplay semantics: EXT. RIVERBANK governs every block after it.
        $segments = $this->splitter->split(
            "EXT. RIVERBANK — DAWN\n\nThe water is still.\n\nHe points at the far shore."
        );

        $this->assertCount(2, $segments);
        $this->assertSame('RIVERBANK — DAWN', $segments[1]->slug);
    }

    public function test_a_dialogue_cue_is_recorded_as_a_speaker_and_stripped_from_narration(): void
    {
        $segments = $this->splitter->split('ADA: Where is the boat?');

        // FR-14 gives the project one voice, so a surviving "ADA:" would be read
        // aloud as "Ada colon, where is the boat".
        $this->assertSame('Where is the boat?', $segments[0]->narration);
        $this->assertSame(['ADA'], $segments[0]->speakers);
    }

    public function test_consecutive_cues_join_into_one_narration_run(): void
    {
        $segments = $this->splitter->split("ADA: Where is it?\nKOFI: I moved it.");

        $this->assertCount(1, $segments);
        $this->assertSame('Where is it? I moved it.', $segments[0]->narration);
        $this->assertSame(['ADA', 'KOFI'], $segments[0]->speakers);
    }

    public function test_a_sentence_containing_a_colon_is_not_mistaken_for_a_cue(): void
    {
        $narration = 'She had one rule that she never broke in all those years: never look back.';

        $this->assertSame([$narration], $this->narrations($narration));
    }

    public function test_a_standalone_parenthetical_becomes_action_not_narration(): void
    {
        $segments = $this->splitter->split("ADA: Go.\n(quietly)\nADA: Now.");

        $this->assertSame('Go. Now.', $segments[0]->narration);
        $this->assertSame('quietly', $segments[0]->action);
    }

    public function test_a_long_block_splits_on_sentence_boundaries(): void
    {
        $sentence = 'The river rose and the village feared for every one of its wooden boats. ';
        $segments = $this->splitter->split(trim(str_repeat($sentence, 6)));

        $this->assertGreaterThan(1, count($segments));

        foreach ($segments as $segment) {
            // Narration that stops mid-sentence sounds broken no matter how good
            // the voice is.
            $this->assertMatchesRegularExpression('/[.!?]$/', $segment->narration);
        }
    }

    public function test_the_cap_merges_the_remainder_rather_than_discarding_it(): void
    {
        $script = implode("\n\n", array_map(fn (int $i) => "Scene body number {$i}.", range(1, 10)));
        $all = $this->splitter->split($script);

        $this->assertCount(10, $all);

        $capped = $this->splitter->capTo($all, 3);

        $this->assertCount(3, $capped);

        // No word of the author's script may be lost to a limit.
        $joined = implode(' ', array_map(fn ($s) => $s->narration, $capped));

        foreach (range(1, 10) as $i) {
            $this->assertStringContainsString("Scene body number {$i}.", $joined);
        }
    }

    public function test_the_merged_tail_keeps_the_speakers_of_every_scene_it_absorbed(): void
    {
        $all = $this->splitter->split("ADA: One.\n\nKOFI: Two.\n\nBIH: Three.");
        $capped = $this->splitter->capTo($all, 2);

        $this->assertCount(2, $capped);
        $this->assertSame(['KOFI', 'BIH'], $capped[1]->speakers);
    }

    public function test_an_empty_script_yields_no_segments(): void
    {
        $this->assertSame([], $this->splitter->split("  \n\n \t "));
    }

    public function test_splitting_is_deterministic(): void
    {
        $script = "INT. HUT — DAY\n\nADA: Where?\n(beat)\n\nShe waits a long time.";

        $this->assertEquals(
            $this->splitter->split($script),
            $this->splitter->split($script),
        );
    }
}
