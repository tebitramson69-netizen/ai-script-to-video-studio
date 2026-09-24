<?php

namespace Tests\Feature;

use App\Contracts\ScriptStructurer;
use App\Enums\AssetType;
use App\Jobs\GenerateSoundEffectsJob;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\VideoAssembler;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-scene ambience, end to end: cue -> effect -> position on the timeline
 * (FR-13, FR-20).
 *
 * The expensive mistakes this file exists to prevent, in order of cost:
 *
 * 1. A CUE NOBODY ASKED FOR. Effects are billed per effect, so a cue detected in
 *    a scene that names no sound is money spent on something that does not belong
 *    in the video — and worse than silence, because it sounds deliberate and
 *    someone has to ask for the render again.
 * 2. AN EFFECT IN THE WRONG SCENE. Offsets are accumulated from the RENDERED
 *    shots, not the scene list. Take the scene list and one unrendered shot
 *    shifts every effect after it.
 * 3. A FILTER GRAPH FFMPEG REFUSES. The audio bed is now built compositionally,
 *    and a malformed graph fails the whole export after every clip has been paid
 *    for.
 *
 * @group slow
 */
class SoundEffectTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(FfmpegRunner::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        Storage::fake('local');

        config(['studio.fake_costs.sfx_per_effect_usd' => 0.02]);
    }

    // ---------------------------------------------------------------- cues

    public function test_a_scene_that_names_a_continuous_sound_gets_a_cue(): void
    {
        $breakdown = app(ScriptStructurer::class)->structure(
            'The rain fell hard on the village all night. Thunder rolled somewhere behind the hills '.
            'and nobody slept until the storm had passed over the valley completely.'
        );

        $this->assertSame('steady rainfall with distant thunder', $breakdown->scenes[0]->sfxCue);
    }

    public function test_a_scene_that_names_no_sound_gets_no_cue(): void
    {
        $breakdown = app(ScriptStructurer::class)->structure(
            'Ada counted the coins twice and decided the price was fair. She folded the paper '.
            'carefully and pushed it back across the table without saying anything more.'
        );

        // Null, not a fallback. Unlike `setting`, which must always produce
        // something because a scene has to render somewhere, a cue that guessed
        // would be charged for.
        $this->assertNull($breakdown->scenes[0]->sfxCue);
    }

    public function test_the_stronger_signal_wins_when_a_scene_names_two_sounds(): void
    {
        $breakdown = app(ScriptStructurer::class)->structure(
            'The market was loud that morning. Traders shouted across the stalls of the market '.
            'and the market crowd pushed past. A little rain had fallen earlier.'
        );

        $this->assertSame(
            'a busy open-air market, overlapping voices and haggling',
            $breakdown->scenes[0]->sfxCue,
        );
    }

    public function test_a_cue_is_matched_whole_word_not_inside_another_word(): void
    {
        // "firewood" and "brainstorm" must not cue fire or a storm. This is the
        // same substring trap that once made "sing" match inside "guessing".
        $breakdown = app(ScriptStructurer::class)->structure(
            'He carried the firewood up the path while she worked through a brainstorm of ideas '.
            'about how the two of them might finally repay what the family owed.'
        );

        $this->assertNull($breakdown->scenes[0]->sfxCue);
    }

    public function test_the_cue_is_persisted_and_survives_to_the_scene_row(): void
    {
        $project = Project::factory()->create([
            'script' => 'The river ran fast below the bridge. Birds called from the far bank '.
                'and the stream carried the broken canoe away downstream toward the falls.',
        ]);

        app(PipelineRunner::class)->parseScript($project);

        $this->assertSame('a flowing river with birdsong', $project->scenes()->first()->sfx_cue);
    }

    // ---------------------------------------------------------------- job

    public function test_only_scenes_with_a_cue_are_charged_for(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire', null, 'steady rainfall with distant thunder']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        $effects = $project->assets()->where('type', AssetType::SoundEffect)->get();

        $this->assertCount(2, $effects, 'An uncued scene was charged for an effect.');
        $this->assertEqualsWithDelta(0.04, (float) $effects->sum('cost_usd'), 0.0001);

        // Each effect knows which scene it belongs to — without that the
        // assembler cannot position it.
        $this->assertCount(2, $effects->pluck('scene_id')->filter()->unique());
    }

    public function test_re_running_does_not_buy_the_same_effect_twice(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire', 'a flowing river with birdsong']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();
        $spend = $project->spentUsd();
        $ids = $project->assets()->where('type', AssetType::SoundEffect)->pluck('id')->sort()->values();

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        $this->assertEqualsWithDelta($spend, $project->spentUsd(), 0.0001);
        $this->assertSame(
            $ids->all(),
            $project->assets()->where('type', AssetType::SoundEffect)->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_editing_a_cue_replaces_that_scenes_effect_and_leaves_the_other_alone(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire', 'a flowing river with birdsong']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        $unchanged = $project->scenes()->where('sequence', 2)->first()->soundEffectAsset();
        $first = $project->scenes()->where('sequence', 1)->first();
        $first->update(['sfx_cue' => 'ocean waves breaking on a shore']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        // The scene whose cue changed is re-bought; the one that did not is not.
        // A cue edit is the normal way a false positive gets corrected, so it must
        // not cost the whole batch again.
        $this->assertSame(
            'ocean waves breaking on a shore',
            $first->fresh()->soundEffectAsset()->meta['description'],
        );
        $this->assertSame($unchanged->id, $project->scenes()->where('sequence', 2)->first()->soundEffectAsset()->id);
    }

    public function test_deleting_a_scene_takes_its_effect_file_with_it(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        $scene = $project->scenes()->first();
        $path = $scene->soundEffectAsset()->path;

        $this->assertTrue(Storage::disk('local')->exists($path));

        $scene->delete();

        // The database constraint only nulls the column. If the file survives, it
        // is a leak the retention purge would never find.
        $this->assertFalse(
            Storage::disk('local')->exists($path),
            'A deleted scene left its sound effect orphaned on disk.',
        );
    }

    // ------------------------------------------------------------ position

    public function test_effects_are_positioned_from_the_rendered_shots_not_the_scene_list(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire', 'a flowing river with birdsong']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());
        $project->refresh();

        $effects = $this->positionedEffects($project);

        // Two scenes, one shot each. The span is 5 seconds rather than the 8 the
        // shot was planned at, because narration is the master clock and the
        // assembler trims to it (FR-18) — so an ambience sized to the planned
        // length would overrun the cut it belongs to.
        $this->assertCount(2, $effects);
        $this->assertSame(0.0, $effects[0]['offset']);
        $this->assertSame(5.0, $effects[0]['span']);
        $this->assertSame(5.0, $effects[1]['offset']);
        $this->assertSame(5.0, $effects[1]['span']);
    }

    public function test_an_unrendered_shot_does_not_shift_the_effects_after_it(): void
    {
        $project = $this->projectWithScenes(['a crackling open fire', 'a flowing river with birdsong']);

        GenerateSoundEffectsJob::dispatchSync($project->getKey());

        // A third scene sits between them on paper but never rendered, so it
        // occupies no time on the finished timeline. Taking the scene list would
        // push scene 2's ambience a whole scene late — into the wrong scene.
        $orphan = Scene::factory()->for($project)->create(['sequence' => 3, 'sfx_cue' => null]);
        Shot::factory()->for($project)->for($orphan)->create([
            'sequence' => 3,
            'target_duration_seconds' => 8.0,
        ]);

        $effects = $this->positionedEffects($project->fresh());

        $this->assertSame(0.0, $effects[0]['offset']);
        $this->assertSame(5.0, $effects[1]['offset']);
    }

    // ------------------------------------------------------------ assembly

    public function test_a_project_with_effects_still_exports_a_playable_file(): void
    {
        $project = $this->renderedProject();

        // Cues set directly rather than detected, so this test is about the MIX:
        // which scenes carry ambience is the structurer's business and is asserted
        // above.
        $project->scenes()->orderBy('sequence')->get()->each(
            fn (Scene $scene) => $scene->update(['sfx_cue' => 'a crackling open fire'])
        );

        app(PipelineRunner::class)->generateAudio($project->fresh());
        $project->refresh();

        $this->assertGreaterThanOrEqual(2, $project->assets()->where('type', AssetType::SoundEffect)->count());
        $this->assertNotNull($project->musicAsset());
        $this->assertNotNull($project->narrationAsset());

        // Narration, music and several positioned effects on one graph. If the
        // compositional filter is malformed, ffmpeg refuses the whole thing here —
        // after every clip has already been paid for.
        $final = app(VideoAssembler::class)->assemble($project);

        $this->assertFileExists($final);
        $this->assertGreaterThan(0, filesize($final));
        $this->assertGreaterThan(5.0, app(FfmpegRunner::class)->durationSeconds($final));
    }

    public function test_the_audio_bed_is_still_built_with_no_effects_at_all(): void
    {
        // The refactor replaced three explicit branches with one graph. The old
        // combinations have to keep working, and this is the common one: no script
        // cues ambience, so the bed is narration plus music exactly as before.
        $project = $this->renderedProject();

        app(PipelineRunner::class)->generateAudio($project->fresh());
        $project->refresh();

        $this->assertSame(0, $project->assets()->where('type', AssetType::SoundEffect)->count());

        $final = app(VideoAssembler::class)->assemble($project);

        $this->assertFileExists($final);
        $this->assertGreaterThan(5.0, app(FfmpegRunner::class)->durationSeconds($final));
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  list<string|null>  $cues  one scene per entry, each with one rendered 8-second shot
     */
    protected function projectWithScenes(array $cues): Project
    {
        $project = Project::factory()->budget(50.00)->create();

        foreach ($cues as $index => $cue) {
            $scene = Scene::factory()->for($project)->create([
                'sequence' => $index + 1,
                'sfx_cue' => $cue,
            ]);

            Shot::factory()->rendered()->for($project)->for($scene)->create([
                'sequence' => $index + 1,
                'narration_segment' => 'Narration for scene number '.($index + 1).' of this story.',
                'target_duration_seconds' => 8.0,

                // The assembler only counts a shot whose clip exists as a row,
                // so the offset arithmetic cannot be exercised without one.
                'asset_id' => Asset::factory()->for($project)->create()->getKey(),
            ]);
        }

        return $project->fresh();
    }

    /**
     * A project with real clips on disk, rendered through the fake drivers.
     *
     * Needed for the assembly tests specifically: they run ffmpeg over the actual
     * files, so factory rows with no media behind them will not do. The script
     * deliberately names no continuous sound, so nothing is cued unless a test
     * sets a cue itself.
     */
    protected function renderedProject(): Project
    {
        $project = Project::factory()->budget(50.00)->create([
            'script' => 'Ada counted the coins twice and decided the price was fair enough. '.
                'She folded the paper and pushed it back across the table.'."\n\n".
                'Later she walked the long way home, thinking about what her mother would say '.
                'when the money finally came back to the family.',
        ]);

        $runner = app(PipelineRunner::class);
        $runner->parseScript($project);
        $runner->planShots($project->fresh());
        $runner->renderShots($project->fresh());

        return $project->fresh();
    }

    /**
     * Reach the assembler's offset arithmetic without rendering anything. It is
     * the piece that drifts silently, so it is worth asserting directly.
     *
     * @return list<array{path: string, offset: float, span: float}>
     */
    protected function positionedEffects(Project $project): array
    {
        $assembler = new class(app(FfmpegRunner::class)) extends VideoAssembler
        {
            public function effectsFor(Project $project): array
            {
                $shots = $project->shots()->with(['asset', 'narrationAsset'])->get()
                    ->filter(fn ($s) => $s->isRendered() && $s->asset !== null)
                    ->values();

                return $this->positionedEffects($project, $shots);
            }
        };

        return $assembler->effectsFor($project);
    }
}
