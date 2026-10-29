<?php

namespace Tests\Feature;

use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Jobs\ReconcileProviderRequestsJob;
use App\Models\Project;
use App\Models\ProviderRequest;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Cost\CostEstimator;
use App\Services\Provider\SubmitWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money that has left the account but has not arrived anywhere yet.
 *
 * Between submit and collection a clip has been billed by the provider and
 * recorded by nothing: usage_records are only written when AssetRecorder lands
 * the asset. Two separate bugs lived in that gap.
 */
class InFlightSpendTest extends TestCase
{
    use RefreshDatabase;

    protected function projectWithShot(ShotStatus $status, float $cap = 10.00): Project
    {
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => 'kling-2-5-turbo-pro',
            'budget_cap_usd' => $cap,
        ]);

        $scene = Scene::factory()->for($project)->create(['sequence' => 1]);

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => 'kling-2-5-turbo-pro',
            'target_duration_seconds' => 10.0,
            'status' => $status,
        ]);

        return $project->fresh();
    }

    /**
     * The cap was blind to a clip it had already authorised.
     *
     * Rendering was absent from the estimator's pending set, and no usage record
     * exists until collection — so a submitted clip appeared in neither. On a
     * 13-shot run that is $4.55 of real spend invisible to every cap check, and
     * the next stage was gated against a project that looked untouched.
     */
    public function test_a_submitted_clip_is_still_counted_against_the_cap(): void
    {
        $project = $this->projectWithShot(ShotStatus::Rendering);

        // Nothing has been recorded as spent: that is the premise, not an
        // incidental. If this ever stops being zero the test proves nothing.
        $this->assertSame(0.0, $project->spentUsd());

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project);

        $this->assertEqualsWithDelta(
            0.70,
            $this->costOfStage($estimate, 'Video clips'),
            0.0001,
            'A clip the provider is already billing for was invisible to the cap.',
        );
    }

    /**
     * The counterweight: counted once, not twice. Once the clip lands it is in
     * spentUsd() and must leave the estimate, or every cap check after a render
     * doubles the bill it thinks it is looking at.
     */
    public function test_a_collected_clip_is_not_counted_twice(): void
    {
        $project = $this->projectWithShot(ShotStatus::Rendered);

        $this->assertNull(
            $this->costOfStage(app(CostEstimator::class)->estimateRemainingRun($project), 'Video clips'),
            'A rendered clip is paid for and recorded; estimating it again double-counts it.',
        );
    }

    /**
     * The grace period was shorter than the client's own worst case.
     *
     * It was timeout + 60 = 180s, on the reasoning that no submit started
     * earlier could still be running. But FalClient retries a
     * ConnectionException and a client-side timeout is one, so a submit may
     * burn attempts x timeout — ~240s at the shipped 2 x 120s. In the 60-second
     * window between, the sweep released a live claim, the owner re-rendered,
     * and the first submit landed afterwards: two clips, one shot.
     */
    public function test_a_submit_that_is_still_running_is_not_released(): void
    {
        $project = $this->projectWithShot(ShotStatus::Rendering);
        $shot = $project->shots()->first();

        // Past the old 180s grace, inside the real worst case.
        $request = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'model' => 'kling-2-5-turbo-pro',
            'fingerprint' => 'still-running',
            'status' => ProviderRequestStatus::Pending,
            'provider_request_id' => null,
            'estimated_cost_usd' => 0.70,
            'subject_type' => Shot::class,
            'subject_id' => $shot->getKey(),
        ]);

        $request->forceFill(['created_at' => now()->subSeconds(200)])->save();

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(
            ProviderRequestStatus::Pending,
            $request->fresh()->status,
            'A submit that may still be running was released, which lets the same clip be bought twice.',
        );
        $this->assertSame(ShotStatus::Rendering, $shot->fresh()->status);
    }

    /**
     * And it is still released eventually — a claim nothing can be doing
     * anything with must not pin its shot in "rendering" for good.
     */
    public function test_a_genuinely_abandoned_claim_is_still_released(): void
    {
        $project = $this->projectWithShot(ShotStatus::Rendering);
        $shot = $project->shots()->first();

        $request = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'model' => 'kling-2-5-turbo-pro',
            'fingerprint' => 'abandoned',
            'status' => ProviderRequestStatus::Pending,
            'provider_request_id' => null,
            'estimated_cost_usd' => 0.70,
            'subject_type' => Shot::class,
            'subject_id' => $shot->getKey(),
        ]);

        $request->forceFill([
            'created_at' => now()->subSeconds(SubmitWindow::graceSeconds() + 60),
        ])->save();

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(ProviderRequestStatus::Failed, $request->fresh()->status);
        $this->assertSame(ShotStatus::Failed, $shot->fresh()->status);
    }

    /**
     * The rule, not just an instance of it: whatever the configured timeout and
     * attempt count, the grace must outlast what the client itself may spend.
     */
    public function test_the_grace_period_outlasts_the_clients_worst_case(): void
    {
        config(['studio.fal.timeout_seconds' => 90, 'studio.fal.request_attempts' => 3]);

        $this->assertGreaterThan(
            3 * 90,
            SubmitWindow::graceSeconds(),
            'The grace period is shorter than a submit may take, so a live submit can be released.',
        );
    }
}
