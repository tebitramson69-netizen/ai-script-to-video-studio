<?php

namespace Tests\Feature;

use App\Contracts\VideoGenerator;
use App\Enums\ProjectStatus;
use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Jobs\ReconcileProviderRequestsJob;
use App\Models\Project;
use App\Models\ProviderRequest;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Media\FfmpegRunner;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * submit → poll → collect, against a driver that really makes you wait.
 *
 * @group slow
 */
class AsyncGenerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(FfmpegRunner::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        Storage::fake('local');

        config([
            'studio.video_generator' => 'fake-queue',
            'studio.fake_queue.polls_before_complete' => 2,
            'studio.video_models.fake.cost_per_second_usd' => 0.10,
        ]);
    }

    protected function projectWithShot(): Project
    {
        $project = Project::factory()->budget(50.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 5.0,
            'status' => ShotStatus::Pending,
        ]);

        return $project->fresh();
    }

    public function test_rendering_submits_without_waiting_for_the_result(): void
    {
        $project = $this->projectWithShot();

        app(PipelineRunner::class)->renderShots($project);

        $shot = $project->shots()->first();

        // The worker handed the work over and was released — it did not block
        // for the duration of the render.
        $this->assertSame(ShotStatus::Rendering, $shot->status);
        $this->assertNull($shot->asset_id);

        $request = ProviderRequest::firstOrFail();
        $this->assertNotNull($request->provider_request_id, 'The provider handle must be persisted immediately.');
        $this->assertSame(ProviderRequestStatus::InQueue, $request->status);
        $this->assertTrue($request->subject->is($shot));
        $this->assertEqualsWithDelta(0.50, (float) $request->estimated_cost_usd, 0.0001);
    }

    public function test_the_pipeline_waits_until_the_provider_is_actually_done(): void
    {
        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        // Two sweeps while the provider is still working.
        foreach ([1, 2] as $sweep) {
            ReconcileProviderRequestsJob::dispatchSync();

            $this->assertSame(
                ShotStatus::Rendering,
                $project->shots()->first()->status,
                "Sweep {$sweep} completed a shot the provider had not finished.",
            );
        }

        ReconcileProviderRequestsJob::dispatchSync();

        $shot = $project->shots()->first();
        $this->assertSame(ShotStatus::Rendered, $shot->status);
        $this->assertNotNull($shot->asset_id);
    }

    public function test_a_collected_result_is_stored_and_costed(): void
    {
        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        foreach (range(1, 3) as $ignored) {
            ReconcileProviderRequestsJob::dispatchSync();
        }

        $request = ProviderRequest::firstOrFail();
        $this->assertSame(ProviderRequestStatus::Completed, $request->status);
        $this->assertNotNull($request->asset_id);
        $this->assertNotNull($request->completed_at);

        // The provider's own figure, reconciled against what we predicted.
        $this->assertNotNull($request->actual_cost_usd);
        $this->assertNotNull($request->costVarianceUsd());

        $asset = $request->asset;
        $this->assertTrue($asset->exists(), 'The clip must land in our own storage, not stay at the provider URL.');
        $this->assertSame('video/mp4', $asset->mime);
    }

    public function test_the_project_advances_once_every_shot_is_collected(): void
    {
        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        foreach (range(1, 3) as $ignored) {
            ReconcileProviderRequestsJob::dispatchSync();
        }

        $this->assertSame(ProjectStatus::ShotsReady, $project->fresh()->status);
    }

    public function test_re_rendering_the_same_shot_does_not_submit_twice(): void
    {
        $project = $this->projectWithShot();

        app(PipelineRunner::class)->renderShots($project);

        // Simulate the owner pressing render again while the first is in flight.
        $project->shots()->update(['status' => ShotStatus::Pending->value]);
        app(PipelineRunner::class)->renderShots($project->fresh());

        // The claim index is what makes this one paid submission, not two.
        $this->assertSame(1, ProviderRequest::count());
    }

    public function test_reconciling_a_finished_request_again_is_harmless(): void
    {
        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        foreach (range(1, 3) as $ignored) {
            ReconcileProviderRequestsJob::dispatchSync();
        }

        $request = ProviderRequest::firstOrFail();
        $assetId = $request->asset_id;

        // A webhook and a poll both firing for the same request is normal.
        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame($assetId, $request->fresh()->asset_id, 'Completion must be idempotent.');
        $this->assertSame(1, $project->fresh()->assets()->count());
    }

    public function test_a_worker_restart_between_submit_and_collect_loses_nothing(): void
    {
        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        // Everything the submitting worker knew is gone; only the ledger remains.
        $this->app->forgetInstance(VideoGenerator::class);

        foreach (range(1, 3) as $ignored) {
            ReconcileProviderRequestsJob::dispatchSync();
        }

        $this->assertSame(ShotStatus::Rendered, $project->shots()->first()->status);
    }

    public function test_the_reconciler_does_nothing_for_a_synchronous_driver(): void
    {
        config(['studio.video_generator' => 'fake']);
        $this->app->forgetInstance(VideoGenerator::class);

        $project = $this->projectWithShot();
        app(PipelineRunner::class)->renderShots($project);

        // The synchronous path renders inline; nothing is ever outstanding.
        $this->assertSame(ShotStatus::Rendered, $project->shots()->first()->status);
        $this->assertSame(0, ProviderRequest::count());

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(ShotStatus::Rendered, $project->shots()->first()->status);
    }
}
