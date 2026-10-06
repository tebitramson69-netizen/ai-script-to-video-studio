<?php

namespace Tests\Feature;

use App\Contracts\VideoGenerator;
use App\Enums\ProjectStatus;
use App\Enums\ProviderFailureReason;
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
use App\Services\Provider\GenerationCompleter;
use App\Services\Provider\GenerationLedger;
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

    public function test_the_sweep_releases_an_abandoned_claim_so_the_shot_can_be_rendered_again(): void
    {
        // The recovery half. The fix to ClaimResult stops new shots being
        // stranded, but a row already stuck stays stuck: renderShots() selects
        // ShotStatus::needingRender(), which is [Pending, Failed, Stale,
        // Purged] - "Rendering" is not in it, so the UI reports "nothing to
        // render" and the shot is unreachable.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();
        $shot->forceFill(['status' => ShotStatus::Rendering])->save();

        $claimed = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'stranded-sweep-1',
            'status' => ProviderRequestStatus::Pending,
            'estimated_cost_usd' => 0.35,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);
        $claimed->forceFill(['created_at' => now()->subMinutes(10)])->save();

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(ProviderRequestStatus::Failed, $claimed->fresh()->status);

        // Back in a state renderShots() will pick up - the whole point.
        $this->assertSame(ShotStatus::Failed, $shot->fresh()->status);
        $this->assertContains($shot->fresh()->status, ShotStatus::needingRender());
    }

    public function test_the_sweep_leaves_a_recent_claim_alone(): void
    {
        // Inside the submit timeout another worker may be in submitClip() right
        // now. Releasing its claim is how one shot becomes two charges.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        $claimed = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'recent-claim-1',
            'status' => ProviderRequestStatus::Pending,
            'estimated_cost_usd' => 0.35,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(
            ProviderRequestStatus::Pending,
            $claimed->fresh()->status,
            'A claim younger than the submit timeout must not be released.',
        );
    }

    public function test_the_sweep_never_releases_a_request_that_has_an_id(): void
    {
        // A slow generation is not an abandoned one. Kling spent 151 seconds of
        // inference on a 5-second clip, so age alone must never release work the
        // provider has acknowledged.
        //
        // Capability is audio.music on purpose. The main reconcile pass filters
        // on video.clip, so using that here would let the normal poll fail this
        // row for an unrelated reason and the assertion could not tell the two
        // apart - which is exactly what the first draft of this test did.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        $submitted = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'audio.music',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'slow-live-sweep-1',
            'status' => ProviderRequestStatus::InProgress,
            'provider_request_id' => 'req-live-slow-2',
            'estimated_cost_usd' => 0.35,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);
        $submitted->forceFill(['created_at' => now()->subHours(2)])->save();

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(
            ProviderRequestStatus::InProgress,
            $submitted->fresh()->status,
            'A submitted request must never be released for being old.',
        );
    }

    public function test_the_release_is_not_limited_to_video(): void
    {
        // Any adapter that claims and then fails to record an id strands its
        // subject the same way, so the release runs before the video driver
        // check and ignores capability.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        $claimed = ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'audio.music',
            'provider' => 'fal',
            'provider_model' => 'fake',
            'fingerprint' => 'stranded-music-1',
            'status' => ProviderRequestStatus::Pending,
            'estimated_cost_usd' => 0.05,
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ]);
        $claimed->forceFill(['created_at' => now()->subMinutes(10)])->save();

        ReconcileProviderRequestsJob::dispatchSync();

        $this->assertSame(ProviderRequestStatus::Failed, $claimed->fresh()->status);
    }

    public function test_a_deliberate_retry_after_a_transient_failure_submits(): void
    {
        // The second dead end, found 2026-10-06. The sweep released the
        // abandoned claim correctly and the shot went Failed - then pressing
        // Render was refused with "This exact request failed before. Change the
        // prompt or reseed before retrying."
        //
        // That reasoning is right for a rejected prompt and wrong for a timeout:
        // the inputs were never the problem, so identical inputs would very
        // likely succeed. Refusing left the fingerprint poisoned for good and no
        // way forward but reseeding.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        app(PipelineRunner::class)->renderShots($project);

        $request = ProviderRequest::where('project_id', $project->getKey())->firstOrFail();

        // Wreck the row into the shape a timed-out submit leaves: claimed, no
        // provider id, older than the grace period. The fake queue driver
        // succeeds, so without this the row has an id - and a row with an id is
        // correctly NOT resubmittable, which is what the first draft of this
        // test accidentally asserted.
        $request->forceFill([
            'provider_request_id' => null,
            'status' => ProviderRequestStatus::Pending,
            'created_at' => now()->subMinutes(10),
        ])->save();

        // What the sweep leaves behind: failed, retryable, never acknowledged.
        app(GenerationCompleter::class)->releaseAbandonedClaim($request->fresh());

        $this->assertSame(ProviderRequestStatus::Failed, $request->fresh()->status);
        $this->assertSame(ShotStatus::Failed, $shot->fresh()->status);

        // The owner presses Render again. It must submit, not refuse.
        app(PipelineRunner::class)->renderShots($project->fresh());

        $this->assertNotNull(
            $request->fresh()->provider_request_id,
            'A deliberate retry after a transient failure must reach the provider.',
        );
        $this->assertSame(ShotStatus::Rendering, $shot->fresh()->status);
    }

    public function test_a_permanent_failure_still_refuses_the_same_inputs(): void
    {
        // The counterweight. A rejected prompt fails identically however many
        // times it is sent, and each attempt may still be billed - so this one
        // must keep refusing until the inputs change.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        app(PipelineRunner::class)->renderShots($project);

        $request = ProviderRequest::where('project_id', $project->getKey())->firstOrFail();

        app(GenerationLedger::class)->markFailed(
            $request,
            ProviderFailureReason::ContentRejected,
            'The prompt was refused by the safety filter.',
        );

        $shot->forceFill(['status' => ShotStatus::Failed])->save();

        app(PipelineRunner::class)->renderShots($project->fresh());

        $this->assertSame(
            ProviderRequestStatus::Failed,
            $request->fresh()->status,
            'A content rejection must not be reopened by a retry.',
        );
    }

    public function test_a_retryable_failure_that_reached_the_provider_is_not_resubmitted(): void
    {
        // Has an id, so the provider acknowledged it and may have billed it.
        // The reconciler owns that lifecycle; resubmitting here would pay twice.
        $project = $this->projectWithShot();
        $shot = $project->shots()->first();

        app(PipelineRunner::class)->renderShots($project);

        $request = ProviderRequest::where('project_id', $project->getKey())->firstOrFail();
        $before = $request->fresh()->provider_request_id;

        $this->assertNotNull($before, 'The fake queue driver should have recorded an id.');

        app(GenerationLedger::class)->markFailed(
            $request,
            ProviderFailureReason::Timeout,
            'Timed out while polling.',
        );

        $shot->forceFill(['status' => ShotStatus::Failed])->save();

        app(PipelineRunner::class)->renderShots($project->fresh());

        $this->assertSame(
            ProviderRequestStatus::Failed,
            $request->fresh()->status,
            'A failure the provider acknowledged must not be resubmitted on the same fingerprint.',
        );
    }
}
