<?php

namespace Tests\Feature;

use App\Enums\ShotStatus;
use App\Exceptions\WouldDestroyPaidWorkException;
use App\Jobs\StructureScriptJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two edits could delete paid clips without asking.
 *
 * Re-parsing runs $project->scenes()->delete() and deleting a scene removes one
 * row; shots.scene_id is cascadeOnDelete(), so either takes the scene's shots
 * with it in the database, with no Eloquent event and no warning. shots.asset_id
 * is the only link to a rendered clip, and usage_records survive — so the file
 * is orphaned while the cap still counts the money, and the re-render is
 * refused part-way for budget that was spent on clips nobody can reach.
 */
class PaidWorkGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function projectWithPaidShot(ShotStatus $status = ShotStatus::Rendered, float $cost = 0.35): Project
    {
        $project = Project::factory()->for(User::factory())->budget(10.00)->create();
        $scene = Scene::factory()->for($project)->create(['sequence' => 1]);

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'status' => $status,
            'cost_usd' => $cost,
        ]);

        return $project->fresh();
    }

    public function test_re_parsing_refuses_to_destroy_rendered_shots(): void
    {
        $project = $this->projectWithPaidShot();

        try {
            app(PipelineRunner::class)->parseScript($project);
            $this->fail('A re-parse was allowed to delete a rendered shot.');
        } catch (WouldDestroyPaidWorkException $e) {
            // The owner has to be able to weigh it, so the message carries both
            // the count and what it cost.
            $this->assertStringContainsString('1 rendered clip(s)', $e->getMessage());
            $this->assertStringContainsString('$0.35', $e->getMessage());
        }

        Queue::assertNotPushed(StructureScriptJob::class);
        $this->assertSame(1, $project->shots()->count());
    }

    /**
     * An in-flight shot counts too, and is the worse case: cost_usd is only
     * written when the clip lands, so the provider is already billing for
     * something this project has no record of.
     */
    public function test_re_parsing_refuses_while_a_shot_is_still_rendering(): void
    {
        $project = $this->projectWithPaidShot(ShotStatus::Rendering, cost: 0.0);

        try {
            app(PipelineRunner::class)->parseScript($project);
            $this->fail('A re-parse was allowed while a clip was still being bought.');
        } catch (WouldDestroyPaidWorkException $e) {
            $this->assertStringContainsString('still rendering', $e->getMessage());
            $this->assertStringContainsString('not recorded yet', $e->getMessage());
        }

        Queue::assertNotPushed(StructureScriptJob::class);
    }

    public function test_a_forced_re_parse_proceeds(): void
    {
        $project = $this->projectWithPaidShot();

        app(PipelineRunner::class)->parseScript($project, force: true);

        Queue::assertPushed(StructureScriptJob::class);
    }

    /**
     * The guard must not get in the way of the ordinary case. A project whose
     * shots are all unrendered — or which has none at all — re-parses freely,
     * which is what happens every time the owner edits a script before paying
     * for anything.
     */
    public function test_a_project_with_nothing_paid_for_re_parses_freely(): void
    {
        $project = Project::factory()->for(User::factory())->budget(10.00)->create();
        $scene = Scene::factory()->for($project)->create(['sequence' => 1]);

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'status' => ShotStatus::Queued,
        ]);

        app(PipelineRunner::class)->parseScript($project->fresh());

        Queue::assertPushed(StructureScriptJob::class);
    }

    /**
     * The runner is the boundary, not the button. A caller that forgets to pass
     * force — an API client, or a future view — must still be refused, which is
     * why the guard is not in the controller.
     */
    public function test_the_route_is_refused_without_force(): void
    {
        $project = $this->projectWithPaidShot();

        $this->actingAs($project->user)
            ->post(route('pipeline.parse', $project))
            ->assertRedirect()
            ->assertSessionHas('budget_error');

        Queue::assertNotPushed(StructureScriptJob::class);
    }

    public function test_deleting_a_scene_refuses_to_destroy_its_paid_shots(): void
    {
        $project = $this->projectWithPaidShot();
        $scene = $project->scenes()->first();

        $this->actingAs($project->user)
            ->delete(route('scenes.destroy', [$project, $scene]))
            ->assertRedirect()
            ->assertSessionHas('budget_error');

        $this->assertDatabaseHas('scenes', ['id' => $scene->id]);
        $this->assertSame(1, $project->shots()->count());
    }

    public function test_deleting_a_scene_with_no_paid_shots_still_works(): void
    {
        $project = Project::factory()->for(User::factory())->budget(10.00)->create();
        $scene = Scene::factory()->for($project)->create(['sequence' => 1]);

        $this->actingAs($project->user)
            ->delete(route('scenes.destroy', [$project, $scene]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('scenes', ['id' => $scene->id]);
    }
}
