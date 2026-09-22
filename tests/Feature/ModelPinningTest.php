<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project pins the model it will render on. Every decision that depends on
 * model facts must honour that pin — otherwise the pin is decorative and the
 * pipeline plans and prices against whatever driver happens to be bound.
 */
class ModelPinningTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_uses_the_projects_model_clip_lengths_not_the_bound_drivers(): void
    {
        // The bound driver offers a 10s clip; the project's model tops out at 8s.
        config(['studio.video_generator' => 'fake']);

        $project = Project::factory()->create([
            'video_model' => 'veo-3-1-fast',
            'budget_cap_usd' => 100,
        ]);

        // 22 words + one full stop is about 9 seconds of speech: longer than
        // veo's 8s maximum, but comfortably inside the fake driver's 10s clip.
        // So the two models must plan it differently — the fake buys one 10s
        // clip, veo has to split it — which is what makes this a real test of
        // whose limits were used.
        Scene::factory()->for($project)->create([
            'narration' => 'The old hunter walked through the long wet grass and he did not '
                .'once look back toward the burning village behind him.',
        ]);

        app(PipelineRunner::class)->planShots($project);

        $lengths = $project->shots()->pluck('target_duration_seconds')->map(fn ($d) => (float) $d)->all();

        $this->assertGreaterThanOrEqual(
            2,
            count($lengths),
            'Nine seconds of narration exceeds veo-3-1-fast\'s 8s maximum and must be split (FR-17). '
            .'One shot means the planner used the bound driver\'s longer clip.',
        );

        foreach ($lengths as $length) {
            $this->assertContains(
                $length,
                [5.0, 6.0, 7.0, 8.0],
                "Planned a {$length}s clip, which veo-3-1-fast cannot render. ".
                'The planner used the bound driver rather than the project model.',
            );
        }
    }

    public function test_the_estimate_uses_the_projects_model_rate_not_the_bound_drivers(): void
    {
        // The fake renders for free; the pinned model does not.
        config([
            'studio.video_generator' => 'fake',
            'studio.video_models.fake.cost_per_second_usd' => 0.0,
        ]);

        $project = Project::factory()->create([
            'video_model' => 'veo-3-1',
            'budget_cap_usd' => 100,
        ]);
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 8.0,
        ]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        // 8s x $0.20 (veo, audio off) = $1.60. Costing it at the fake's $0.00
        // would let an unaffordable run straight past the budget cap.
        $this->assertEqualsWithDelta(1.60, $estimate->estimatedUsd(), 0.0001);
    }

    public function test_the_clip_request_names_the_model_it_should_render_on(): void
    {
        config(['studio.video_generator' => 'fake']);

        $project = Project::factory()->create([
            'video_model' => 'veo-3-1-fast',
            'budget_cap_usd' => 100,
        ]);
        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 8.0,
        ]);

        app(PipelineRunner::class)->renderShots($project);

        // Without the model on the request the adapter cannot know which
        // endpoint to call, and an aggregator hosting many models has no way to
        // route it.
        $asset = $shot->fresh()->asset;
        $this->assertNotNull($asset);
        $this->assertSame('veo-3-1-fast', $asset->meta['model_key'] ?? null);
    }
}
