<?php

namespace Tests\Feature;

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
 * Two different shots can, in principle, hash to the same fingerprint — same
 * prompt, same duration, same seed. Deduplication must not strand either of
 * them, because a shot stuck in "rendering" never completes and never fails, so
 * the project can never be exported and nothing tells the owner why.
 *
 * @group slow
 */
class FingerprintCollisionTest extends TestCase
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
            'studio.fake_queue.polls_before_complete' => 0,
        ]);
    }

    public function test_two_shots_with_identical_inputs_both_complete(): void
    {
        $project = Project::factory()->budget(100.00)->create();
        $scene = Scene::factory()->for($project)->create();

        // Deliberately identical in every field the fingerprint covers.
        foreach ([1, 2] as $sequence) {
            Shot::factory()->for($project)->for($scene)->create([
                'sequence' => $sequence,
                'prompt' => 'a riverbank at dawn. cinematic.',
                'seed' => 424242,
                'target_duration_seconds' => 5.0,
                'status' => ShotStatus::Pending,
            ]);
        }

        app(PipelineRunner::class)->renderShots($project);

        foreach (range(1, 3) as $ignored) {
            ReconcileProviderRequestsJob::dispatchSync();
        }

        $shots = $project->shots()->orderBy('sequence')->get();

        foreach ($shots as $shot) {
            $this->assertNotSame(
                ShotStatus::Rendering,
                $shot->status,
                "Shot #{$shot->sequence} is stranded mid-render: it will never complete and never fail.",
            );
        }

        foreach ($shots as $shot) {
            $this->assertSame(
                ShotStatus::Rendered,
                $shot->status,
                "Shot #{$shot->sequence} did not reach a rendered state.",
            );
            $this->assertNotNull($shot->asset_id, "Shot #{$shot->sequence} has no clip.");
        }
    }

    public function test_a_collision_is_resolved_by_reseeding_rather_than_hijacking(): void
    {
        $project = Project::factory()->budget(100.00)->create();
        $scene = Scene::factory()->for($project)->create();

        foreach ([1, 2] as $sequence) {
            Shot::factory()->for($project)->for($scene)->create([
                'sequence' => $sequence,
                'prompt' => 'a riverbank at dawn. cinematic.',
                'seed' => 424242,
                'target_duration_seconds' => 5.0,
                'status' => ShotStatus::Pending,
            ]);
        }

        app(PipelineRunner::class)->renderShots($project);

        // Genuinely distinct work deserves its own request; sharing one would
        // leave whichever shot lost the race without a result.
        $this->assertSame(2, ProviderRequest::count());

        $seeds = $project->shots()->pluck('seed')->unique();
        $this->assertCount(2, $seeds, 'The colliding shot should have been reseeded.');
    }

    public function test_resubmitting_the_same_shot_still_deduplicates(): void
    {
        $project = Project::factory()->budget(100.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'seed' => 999,
            'target_duration_seconds' => 5.0,
            'status' => ShotStatus::Pending,
        ]);

        app(PipelineRunner::class)->renderShots($project);

        // The same shot asked for twice is a genuine duplicate and must not
        // become a second paid submission.
        $project->shots()->update(['status' => ShotStatus::Pending->value]);
        app(PipelineRunner::class)->renderShots($project->fresh());

        $this->assertSame(1, ProviderRequest::count());
    }
}
