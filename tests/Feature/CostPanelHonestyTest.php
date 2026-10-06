<?php

namespace Tests\Feature;

use App\Enums\ShotStatus;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
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
