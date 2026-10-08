<?php

namespace Tests\Feature;

use App\Enums\ShotStatus;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Cost\CostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the cost panel is allowed to claim about money.
 *
 * Found on the first real run (2026-10-06). The panel showed **Spent $0.35** of
 * genuinely charged money and, directly beneath it, "Every line item estimates
 * $0.00 because the active drivers are the local fake set".
 *
 * The note hung off `significantLineItems()` being empty, which is a different
 * fact entirely: a finished run has nothing left to estimate whatever driver is
 * bound. Of all the places to be casually wrong, telling an owner their spend is
 * pretend is the worst — it is the sentence that decides whether they treat the
 * next click as free.
 */
class CostPanelHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function renderedProject(User $user): Project
    {
        $project = Project::factory()->for($user)->budget(1.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 5.0,
            'status' => ShotStatus::Rendered,
            'cost_usd' => 0.35,
        ]);

        return $project->fresh();
    }

    public function test_a_finished_run_on_a_real_driver_is_never_called_fake(): void
    {
        config(['studio.video_generator' => 'fal']);

        $user = User::factory()->create();
        $project = $this->renderedProject($user);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('the active drivers are the local')
            ->assertSee('real money already charged');
    }

    public function test_one_real_driver_among_the_fakes_is_enough(): void
    {
        // Pessimistic on purpose: a single real driver means this run can spend,
        // and the panel must not say otherwise because the other four are local.
        config([
            'studio.video_generator' => 'fake',
            'studio.image_generator' => 'fake',
            'studio.speech_synthesizer' => 'fake',
            'studio.music_generator' => 'fake',
            'studio.sound_effect_generator' => 'fal',
        ]);

        $user = User::factory()->create();
        $project = $this->renderedProject($user);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('the active drivers are the local');
    }

    /**
     * A project whose clips each outrun their narration, which is the normal
     * case: the planner rounds UP to a length the model will render (FR-16).
     */
    protected function slackProject(User $user): Project
    {
        $project = Project::factory()->for($user)->budget(5.00)->create();
        $scene = Scene::factory()->for($project)->create();

        for ($i = 1; $i <= 5; $i++) {
            Shot::factory()->for($project)->for($scene)->create([
                'sequence' => $i,
                'target_duration_seconds' => 5.0,
                'narration_duration_seconds' => 2.3,
                'status' => ShotStatus::Rendered,
            ]);
        }

        return $project->fresh();
    }

    /**
     * Found on the first real export (2026-10-07). The panel read "Estimated
     * runtime 25.0s" above a finished file that played for 11.60s, because the
     * figure was the sum of the clip lengths BOUGHT rather than the narration
     * timeline the assembler trims to (FR-18).
     */
    public function test_estimated_runtime_is_the_timeline_not_the_clips_bought(): void
    {
        $user = User::factory()->create();
        $project = $this->slackProject($user);

        $this->assertEqualsWithDelta(
            11.5,
            app(CostEstimator::class)->estimatedRuntimeSeconds($project),
            0.01,
            'Runtime must be the narration timeline the export actually runs for.',
        );
    }

    public function test_the_trimmed_surplus_is_reported_rather_than_hidden(): void
    {
        $user = User::factory()->create();
        $project = $this->slackProject($user);

        // 25.0s bought, 11.5s used.
        $this->assertEqualsWithDelta(
            13.5,
            app(CostEstimator::class)->trimmedSurplusSeconds($project),
            0.01,
        );

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Trimmed surplus')
            ->assertSee('13.5s');
    }

    public function test_a_project_with_no_slack_shows_no_surplus_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->budget(1.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 5.0,
            'narration_duration_seconds' => 5.0,
            'status' => ShotStatus::Rendered,
        ]);

        $this->assertSame(0.0, app(CostEstimator::class)->trimmedSurplusSeconds($project->fresh()));

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee('Trimmed surplus');
    }

    /**
     * The pre-planning path: a price has to be showable on the scene-review
     * screen, before a single shot row exists.
     */
    public function test_runtime_falls_back_to_the_narration_estimate_before_shots_exist(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->budget(1.00)->create();
        Scene::factory()->for($project)->create();

        $this->assertGreaterThan(
            0.0,
            app(CostEstimator::class)->estimatedRuntimeSeconds($project->fresh()),
        );
    }

    /**
     * GenerateMusicJob generates against the timeline. The estimate priced the
     * same track off the clip seconds, so the two disagreed about one asset.
     */
    public function test_music_is_priced_on_the_timeline_it_will_be_generated_for(): void
    {
        $user = User::factory()->create();
        $project = $this->slackProject($user);

        $labels = array_keys(app(CostEstimator::class)->estimateRemainingRun($project)->lineItems);
        $music = array_values(array_filter($labels, fn (string $l) => str_starts_with($l, 'Music')));

        $this->assertCount(1, $music, 'The project has no music asset, so a music line item is expected.');

        // 11.5s of timeline, not the 25.0s of clip that was bought.
        $this->assertSame('Music (12s)', $music[0]);
    }

    public function test_an_all_local_run_still_says_so(): void
    {
        // The note is genuinely useful when it is true — it is what tells a new
        // owner why every figure reads $0.00.
        config([
            'studio.video_generator' => 'fake',
            'studio.image_generator' => 'fake',
            'studio.speech_synthesizer' => 'fake',
            'studio.music_generator' => 'fake',
            'studio.sound_effect_generator' => 'fake',
        ]);

        $user = User::factory()->create();
        $project = $this->renderedProject($user);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('the active drivers are the local');
    }
}
