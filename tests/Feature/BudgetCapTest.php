<?php

namespace Tests\Feature;

use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FR-11 / NFR-4: the per-project cap is hard and enforced before anything runs.
 */
class BudgetCapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fake driver costs $0, so these tests price it deliberately to exercise
     * the cap. Without a price there is nothing for a budget to cap.
     */
    protected function priceVideoAt(float $perSecond): void
    {
        config(['studio.video_models.fake.cost_per_second_usd' => $perSecond]);
    }

    public function test_an_affordable_run_passes_the_check(): void
    {
        $this->priceVideoAt(0.10);

        $project = Project::factory()->budget(15.00)->create();
        $scene = Scene::factory()->for($project)->create();

        // 3 shots × 8s × $0.10 = $2.40, comfortably inside $15.
        for ($i = 1; $i <= 3; $i++) {
            Shot::factory()->for($project)->for($scene)
                ->create(['sequence' => $i, 'target_duration_seconds' => 8.0]);
        }

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $this->assertFalse($estimate->exceedsBudget());
        $this->assertEqualsWithDelta(2.40, $estimate->estimatedUsd(), 0.001);
    }

    public function test_a_run_over_the_cap_throws_before_any_job_is_dispatched(): void
    {
        $this->priceVideoAt(0.40);

        $project = Project::factory()->budget(5.00)->create();
        $scene = Scene::factory()->for($project)->create();

        // 4 shots × 10s × $0.40 = $16.00 against a $5.00 cap.
        for ($i = 1; $i <= 4; $i++) {
            Shot::factory()->for($project)->for($scene)
                ->create(['sequence' => $i, 'target_duration_seconds' => 10.0]);
        }

        Queue::fake();

        $this->expectException(BudgetExceededException::class);

        try {
            app(PipelineRunner::class)->renderShots($project->fresh());
        } finally {
            // Nothing may be queued: the cap must stop the run, not merely warn.
            Queue::assertNothingPushed();
        }
    }

    public function test_the_estimate_counts_money_already_spent(): void
    {
        $this->priceVideoAt(0.10);

        $project = Project::factory()->budget(5.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 1, 'target_duration_seconds' => 10.0]);

        $project->usageRecords()->create([
            'provider' => 'fake', 'operation' => 'video.clip',
            'units' => 1, 'cost_usd' => 4.50, 'outcome' => 'succeeded',
        ]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $this->assertEqualsWithDelta(4.50, $estimate->alreadySpentUsd, 0.001);
        $this->assertEqualsWithDelta(5.50, $estimate->projectedTotalUsd(), 0.001);
        $this->assertTrue($estimate->exceedsBudget(), '$5.50 projected against a $5.00 cap must fail.');
    }

    public function test_already_rendered_shots_are_not_re_estimated(): void
    {
        $this->priceVideoAt(0.10);

        $project = Project::factory()->budget(15.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->rendered()->for($project)->for($scene)
            ->create(['sequence' => 1, 'target_duration_seconds' => 10.0]);
        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 2, 'target_duration_seconds' => 10.0]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        // NFR-3: re-running a stage must not double-charge for finished work.
        $this->assertEqualsWithDelta(1.00, $estimate->estimatedUsd(), 0.001);
    }

    public function test_stale_and_failed_shots_are_re_estimated(): void
    {
        $this->priceVideoAt(0.10);

        $project = Project::factory()->budget(15.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1, 'target_duration_seconds' => 10.0,
            'status' => ShotStatus::Stale,
        ]);
        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 2, 'target_duration_seconds' => 10.0,
            'status' => ShotStatus::Failed,
        ]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $this->assertEqualsWithDelta(2.00, $estimate->estimatedUsd(), 0.001);
    }

    public function test_the_exception_message_names_the_actual_numbers(): void
    {
        $this->priceVideoAt(1.00);

        $project = Project::factory()->budget(2.00)->create();
        $scene = Scene::factory()->for($project)->create();
        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 1, 'target_duration_seconds' => 10.0]);

        try {
            app(CostEstimator::class)->assertWithinBudget($project->fresh());
            $this->fail('Expected BudgetExceededException.');
        } catch (BudgetExceededException $e) {
            // The owner needs the figures, not "an error occurred".
            $this->assertStringContainsString('10.00', $e->getMessage());
            $this->assertStringContainsString('2.00', $e->getMessage());
        }
    }
}
