<?php

namespace Tests\Feature;

use App\Enums\AspectRatio;
use App\Enums\ShotStatus;
use App\Jobs\PlanShotsJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Provider\ModelRegistry;
use App\Services\Timing\ShotPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One project, two models.
 *
 * A text-to-video model cannot hold a character's face between cuts; an
 * image-to-video model cannot render an empty street. Real scripts contain
 * both kinds of shot, so a single pin is wrong for the whole video — that is
 * the problem this solves.
 *
 * The rule everything else follows: a shot renders on the model it was PLANNED
 * on. Clip-length ladders are per model, so a shot planned at 8 seconds against
 * Veo cannot be handed to Kling, whose ladder is 5 or 10.
 */
class PerShotModelSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected const T2V = 'kling-2-5-turbo-pro';

    protected const I2V = 'kling-2-5-turbo-pro-i2v';

    protected function registry(): ModelRegistry
    {
        return app(ModelRegistry::class);
    }

    protected function pairedProject(?string $companion = self::I2V): Project
    {
        return Project::factory()->for(User::factory())->create([
            'video_model' => self::T2V,
            'video_model_i2v' => $companion,
            'aspect_ratio' => AspectRatio::Landscape->value,
        ]);
    }

    protected function lockedCharacter(Project $project): Character
    {
        $asset = Asset::factory()->for($project)->create();

        return Character::factory()->for($project)->create([
            'canonical_reference_asset_id' => $asset->id,
        ]);
    }

    // ---- Resolution --------------------------------------------------------

    public function test_a_shot_with_a_locked_reference_resolves_to_the_companion(): void
    {
        $project = $this->pairedProject();

        $this->assertSame(
            self::I2V,
            $this->registry()->resolveForShot($project, hasLockedReference: true)->key,
        );
    }

    public function test_a_shot_without_one_stays_on_the_primary(): void
    {
        $project = $this->pairedProject();

        $this->assertSame(
            self::T2V,
            $this->registry()->resolveForShot($project, hasLockedReference: false)->key,
        );
    }

    public function test_no_companion_means_the_primary_renders_everything(): void
    {
        // Exactly the behaviour this project had before per-shot selection
        // existed. Null is not a degraded mode, it is the default.
        $project = $this->pairedProject(companion: null);

        $this->assertSame(self::T2V, $this->registry()->resolveForShot($project, true)->key);
        $this->assertSame(self::T2V, $this->registry()->resolveForShot($project, false)->key);
        $this->assertNull($this->registry()->imageModelForProject($project));
    }

    public function test_a_companion_that_cannot_do_image_to_video_is_ignored_and_reported(): void
    {
        // Silently accepting it would leave the owner believing their character
        // shots are being handled when every one of them renders from the
        // prompt alone.
        $project = $this->pairedProject(companion: self::T2V);

        $this->assertNull($this->registry()->imageModelForProject($project));

        $this->lockedCharacter($project);

        $this->assertStringContainsString(
            'cannot do image-to-video',
            implode(' ', $this->registry()->degradationWarnings($project)),
        );
    }

    // ---- The planned model is the rendered model ---------------------------

    public function test_a_shot_renders_on_the_model_it_was_planned_on(): void
    {
        $project = $this->pairedProject();
        $scene = Scene::factory()->for($project)->create();

        $shot = Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => self::I2V,
        ]);

        // No locked character, so live resolution would say primary. The
        // stamped model wins: its ladder is what produced this duration.
        $this->assertSame(self::I2V, $this->registry()->forShot($shot->fresh('characters'))->key);
    }

    public function test_a_model_the_project_never_chose_is_treated_as_a_stale_row(): void
    {
        // The money-safety rule. 'fake' costs zero, so honouring an arbitrary
        // key would let a zero-rate row wave an unaffordable run past the
        // budget cap (FR-11, NFR-4).
        $project = $this->pairedProject();
        $scene = Scene::factory()->for($project)->create();

        $shot = Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => 'fake',
        ]);

        $this->assertSame(self::T2V, $this->registry()->forShot($shot->fresh('characters'))->key);
    }

    // ---- Cost --------------------------------------------------------------

    public function test_the_estimate_carries_both_rates_at_once(): void
    {
        // Paired across two genuinely different rates on purpose. Both Kling
        // entries are $0.07/s, so pairing them could not tell a correct
        // per-model estimate apart from a lazy one that prices everything at
        // the primary.
        $project = $this->pairedProject(companion: 'veo-3-1-fast');
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => self::T2V,
            'target_duration_seconds' => 10,
            'status' => ShotStatus::Pending,
        ]);

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 2,
            'model' => 'veo-3-1-fast',
            'target_duration_seconds' => 10,
            'status' => ShotStatus::Pending,
        ]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project);

        // 10s at $0.07 + 10s at $0.20. Pricing the whole run at the primary's
        // rate would understate it by $1.30 — and an understated estimate is
        // one the cap waves through.
        $video = collect($estimate->lineItems)
            ->filter(fn ($v, $k) => str_starts_with($k, 'Video clips'))
            ->sum();

        $this->assertEqualsWithDelta(0.70 + 2.00, $video, 0.0001);
    }

    // ---- Compatibility -----------------------------------------------------

    public function test_a_ratio_only_one_of_the_pair_can_render_is_refused(): void
    {
        $project = $this->pairedProject();
        $project->forceFill(['aspect_ratio' => AspectRatio::Square->value])->save();

        // Neither Kling entry declares 1:1, and refusing here costs nothing —
        // refusing halfway through a paid run costs the shots already rendered.
        $this->assertNotNull($this->registry()->incompatibilityReason($project->fresh()));
    }

    public function test_a_paired_project_raises_no_consistency_warning(): void
    {
        $project = $this->pairedProject();
        $this->lockedCharacter($project);

        // The pair covers image-to-video, so warning about drift anyway would
        // train the owner to ignore the warnings that still mean something.
        $this->assertSame([], $this->registry()->degradationWarnings($project));
    }

    // ---- Planning ----------------------------------------------------------

    public function test_planning_splits_the_models_across_one_script(): void
    {
        // The feature actually working, rather than its parts. One script, one
        // project, two models — a character scene and an empty-street scene
        // planned onto different endpoints in a single pass.
        $project = $this->pairedProject();
        $character = $this->lockedCharacter($project);
        $character->forceFill(['name' => 'Ada'])->save();
        $project->load('characters');

        Scene::factory()->for($project)->create([
            'sequence' => 1,
            'narration' => 'Ada stepped onto the bridge.',
            'action' => 'Ada looks down at the water',
            'setting' => 'a stone bridge',
        ]);

        Scene::factory()->for($project)->create([
            'sequence' => 2,
            'narration' => 'The street below was empty.',
            'action' => 'wind moves a scrap of paper',
            'setting' => 'an empty street',
        ]);

        app(PlanShotsJob::class, ['projectId' => $project->id])->handle(
            app(ShotPlanner::class),
            app(ProjectStateMachine::class),
        );

        $project->refresh()->load('shots.scene');

        $byScene = $project->shots->groupBy(fn ($shot) => $shot->scene->sequence);

        $this->assertSame(self::I2V, $byScene[1]->first()->model, 'Ada has a locked reference.');
        $this->assertSame(self::T2V, $byScene[2]->first()->model, 'The empty street has none.');
    }

    // ---- The form ----------------------------------------------------------

    public function test_the_pair_can_be_chosen_at_creation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Paired project',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
            'video_model' => self::T2V,
            'video_model_i2v' => self::I2V,
            'script' => 'The river was calm that morning.',
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'title' => 'Paired project',
            'video_model' => self::T2V,
            'video_model_i2v' => self::I2V,
        ]);
    }

    public function test_choosing_the_same_model_twice_stores_no_companion(): void
    {
        // Not a pairing — the default dressed up. Storing it would make every
        // later "is a companion set?" check answer yes misleadingly.
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Same twice',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
            'video_model' => self::T2V,
            'video_model_i2v' => self::T2V,
            'script' => 'The river was calm that morning.',
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'title' => 'Same twice',
            'video_model_i2v' => null,
        ]);
    }

    public function test_an_unregistered_model_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'Bogus model',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
            'video_model' => 'definitely-not-a-model',
            'script' => 'The river was calm that morning.',
        ])->assertSessionHasErrors('video_model');

        $this->assertDatabaseMissing('projects', ['title' => 'Bogus model']);
    }

    public function test_omitting_the_models_keeps_the_previous_default_behaviour(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'title' => 'No models named',
            'aspect_ratio' => '16:9',
            'budget_cap_usd' => '10',
            'script' => 'The river was calm that morning.',
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'title' => 'No models named',
            'video_model' => app(ModelRegistry::class)->defaultVideo()->key,
            'video_model_i2v' => null,
        ]);
    }
}
