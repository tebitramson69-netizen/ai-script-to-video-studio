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
use App\Services\Pipeline\ProgressSnapshot;
use App\Services\Provider\ClaimResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_a_sweep_records_that_it_ran(): void
    {
        // The heartbeat behind the "generations are not being collected"
        // warning. If the sweep stopped writing it, the warning would fire
        // while reconciliation was working perfectly - and a warning that cries
        // wolf is one the owner learns to ignore, which costs the money it was
        // added to save.
        Cache::forget(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY);

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertNotNull(
            Cache::get(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY),
            'The sweep ran but left no heartbeat.',
        );
    }

    public function test_the_heartbeat_is_recorded_even_with_a_synchronous_driver(): void
    {
        // The sweep returns early when the bound driver answers synchronously.
        // The heartbeat must still be written: the question it answers is
        // whether the scheduler is running, not whether this sweep had work.
        config(['studio.drivers.video_generator.default' => 'fake']);
        Cache::forget(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY);

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertNotNull(Cache::get(ProgressSnapshot::RECONCILER_HEARTBEAT_KEY));
    }

    public function test_a_submit_that_never_reached_the_provider_releases_the_shot(): void
    {
        // Reproduces 2026-10-05 exactly. The submit POST timed out at 120s, so
        // the claim was written but no provider id was ever recorded. The retry
        // read that row as "in flight", declined to resubmit, and the shot sat
        // in Rendering for good - the reconciler filters on a non-null provider
        // id, so nothing could ever sweep it.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        // The wreckage a timed-out submit leaves: claimed, Pending, no id.
        $claimed = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'abandoned-claim-1',
            'status' => ProviderRequestStatus::Pending,
            'estimated_cost_usd' => 0.50,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);

        // Past the submit timeout, so no POST started earlier can still be live.
        $claimed->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $claim = new ClaimResult($claimed->fresh(), isNew: false);

        $this->assertFalse(
            $claim->isDuplicateInFlight(),
            'A claim with no provider id must never be treated as in flight.',
        );
        $this->assertTrue($claim->isAbandonedClaim(180));
    }

    public function test_a_slow_generation_is_never_mistaken_for_an_abandoned_one(): void
    {
        // The counterweight, and the reason the grace period is tied to the
        // submit timeout rather than to generation time: Kling spent 151 seconds
        // of inference on a 5-second clip. Age must not release work the
        // provider has acknowledged.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        $submitted = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'slow-but-live-1',
            'status' => ProviderRequestStatus::InProgress,
            'provider_request_id' => 'req-live-slow',
            'estimated_cost_usd' => 0.50,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);

        $submitted->forceFill(['created_at' => now()->subHour()])->save();

        $claim = new ClaimResult($submitted->fresh(), isNew: false);

        $this->assertFalse($claim->isAbandonedClaim(180));
        $this->assertTrue($claim->isDuplicateInFlight());
    }
}
