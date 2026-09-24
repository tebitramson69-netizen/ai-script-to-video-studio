<?php

namespace Tests\Feature;

use App\Contracts\Data\CharacterDraft;
use App\Contracts\Data\SceneDraft;
use App\Contracts\Data\ScriptBreakdown;
use App\Enums\SceneMood;
use App\Services\Script\ScriptBreakdownValidator;
use LogicException;
use Tests\TestCase;

/**
 * The guarantees, enforced.
 *
 * Every assertion here corresponds to a line in
 * docs/SCRIPT-STRUCTURER.md §2 "Guaranteed by the schema". Downstream code is
 * allowed to rely on those without checking, which is only safe if something
 * refuses to emit a breakdown that breaks them.
 *
 * Feature rather than Unit because the validator reads studio.limits from config.
 */
class ScriptBreakdownValidatorTest extends TestCase
{
    protected ScriptBreakdownValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ScriptBreakdownValidator;
    }

    protected function scene(array $overrides = []): SceneDraft
    {
        return new SceneDraft(
            setting: $overrides['setting'] ?? 'a riverbank',
            narration: $overrides['narration'] ?? 'The water is still.',
            mood: $overrides['mood'] ?? SceneMood::Neutral,
            action: $overrides['action'] ?? null,
            characterNames: $overrides['characterNames'] ?? [],
            sfxCue: $overrides['sfxCue'] ?? null,
        );
    }

    protected function expectRejection(string $fragment): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($fragment, '/').'/i');
    }

    public function test_a_blank_sfx_cue_is_rejected_because_null_is_how_you_say_no_ambience(): void
    {
        // A blank cue would reach the generator as a prompt and be paid for. The
        // distinction between null and '' is the difference between "this scene
        // has no ambience" and "buy me whatever you imagine".
        $this->expectRejection('blank sfxCue');

        $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene(['sfxCue' => '   '])]));
    }

    public function test_an_over_long_sfx_cue_is_rejected(): void
    {
        // A sound-effect prompt that runs on reads as a scene description, and the
        // model renders the wrong thing at full price.
        $this->expectRejection('exceeds 120 characters');

        $this->validator->validate(new ScriptBreakdown(scenes: [
            $this->scene(['sfxCue' => str_repeat('rain on a tin roof, ', 12)]),
        ]));
    }

    public function test_a_null_sfx_cue_is_the_normal_case_and_passes(): void
    {
        $breakdown = $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene()]));

        $this->assertNull($breakdown->scenes[0]->sfxCue);
    }

    public function test_a_well_formed_breakdown_passes_through_unchanged(): void
    {
        $breakdown = new ScriptBreakdown(
            scenes: [$this->scene(['characterNames' => ['Ada']])],
            characters: [new CharacterDraft('Ada', 'Ada wore a red cloth.')],
        );

        $this->assertSame($breakdown, $this->validator->validate($breakdown));
    }

    public function test_an_empty_breakdown_is_refused(): void
    {
        // An unparseable script must raise InvalidScriptException. Reaching here
        // with no scenes means a heuristic returned nothing and said nothing.
        $this->expectRejection('no scenes');

        $this->validator->validate(new ScriptBreakdown(scenes: []));
    }

    public function test_more_scenes_than_the_cap_is_refused(): void
    {
        config()->set('studio.limits.max_scenes', 2);

        $this->expectRejection('the cap is 2');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(), $this->scene(), $this->scene()],
        ));
    }

    public function test_empty_narration_is_refused(): void
    {
        $this->expectRejection('nothing to narrate');

        $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene(['narration' => '   '])]));
    }

    public function test_untrimmed_narration_is_refused(): void
    {
        $this->expectRejection('leading or trailing whitespace');

        $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene(['narration' => " The water.\n"])]));
    }

    public function test_a_blank_line_inside_narration_is_refused(): void
    {
        $this->expectRejection('blank line');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(['narration' => "One.\n\nTwo."])],
        ));
    }

    /**
     * The guarantee that stops the narrator saying "Ada colon, where is the boat".
     */
    public function test_a_surviving_dialogue_cue_in_narration_is_refused(): void
    {
        $this->expectRejection('dialogue cue');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(['narration' => 'ADA: Where is the boat?'])],
        ));
    }

    public function test_an_ordinary_colon_inside_a_sentence_is_not_mistaken_for_a_cue(): void
    {
        $breakdown = new ScriptBreakdown(scenes: [$this->scene([
            'narration' => 'She had one rule that she kept for all those years: never look back.',
        ])]);

        $this->assertSame($breakdown, $this->validator->validate($breakdown));
    }

    public function test_an_empty_setting_is_refused(): void
    {
        $this->expectRejection('empty setting');

        $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene(['setting' => ' '])]));
    }

    public function test_an_overlong_setting_is_refused(): void
    {
        $this->expectRejection('exceeds 200');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(['setting' => str_repeat('a', 201)])],
        ));
    }

    public function test_a_blank_action_is_refused_because_null_means_absent(): void
    {
        $this->expectRejection('blank action');

        $this->validator->validate(new ScriptBreakdown(scenes: [$this->scene(['action' => '  '])]));
    }

    /**
     * Referential integrity. Scene attribution is persisted and read back as the
     * shot's cast, so a name with no character behind it becomes a shot that
     * cannot resolve its reference.
     */
    public function test_a_scene_naming_a_character_outside_the_cast_is_refused(): void
    {
        $this->expectRejection('not in the breakdown');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(['characterNames' => ['Ada']])],
            characters: [],
        ));
    }

    public function test_a_scene_listing_the_same_character_twice_is_refused(): void
    {
        $this->expectRejection('twice');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene(['characterNames' => ['Ada', 'Ada']])],
            characters: [new CharacterDraft('Ada')],
        ));
    }

    public function test_duplicate_characters_are_refused(): void
    {
        $this->expectRejection('appears twice');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene()],
            characters: [new CharacterDraft('Ada'), new CharacterDraft('Ada')],
        ));
    }

    public function test_more_characters_than_the_cap_is_refused(): void
    {
        $this->expectRejection('the cap is 6');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene()],
            characters: array_map(
                fn (int $i) => new CharacterDraft("Name{$i}"),
                range(1, 7),
            ),
        ));
    }

    public function test_an_empty_character_name_is_refused(): void
    {
        $this->expectRejection('empty name');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene()],
            characters: [new CharacterDraft('  ')],
        ));
    }

    public function test_a_blank_character_description_is_refused_because_null_means_absent(): void
    {
        $this->expectRejection('blank description');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene()],
            characters: [new CharacterDraft('Ada', ' ')],
        ));
    }

    public function test_a_blank_warning_is_refused(): void
    {
        $this->expectRejection('non-empty strings');

        $this->validator->validate(new ScriptBreakdown(
            scenes: [$this->scene()],
            warnings: [''],
        ));
    }
}
