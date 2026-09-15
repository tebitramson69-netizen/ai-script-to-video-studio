<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Enums\ShotStatus;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Retention\RetentionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NFR-7: keep the final .mp4 and locked references; allow purging intermediate
 * shot clips after a successful export; show storage used.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    protected RetentionManager $retention;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->retention = app(RetentionManager::class);
    }

    public function test_it_refuses_to_purge_a_project_that_has_not_exported(): void
    {
        $project = $this->exportedProject();
        $project->forceFill(['final_asset_id' => null, 'exported_at' => null])->save();

        $reason = $this->retention->purgeBlockedReason($project->fresh());

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Nothing has been exported', $reason);
        $this->assertFalse($this->retention->canPurge($project->fresh()));
    }

    public function test_it_refuses_to_purge_while_shots_are_stale(): void
    {
        $project = $this->exportedProject();
        $project->shots()->update(['status' => ShotStatus::Stale->value]);

        $this->assertStringContainsString(
            'stale',
            (string) $this->retention->purgeBlockedReason($project->fresh()),
        );
    }

    public function test_purging_deletes_clips_and_reclaims_their_bytes(): void
    {
        $project = $this->exportedProject();
        $clipPaths = $project->assets()->where('type', AssetType::ShotClip)->pluck('path');

        $this->assertTrue($this->retention->canPurge($project));

        $reclaimed = $this->retention->purge($project);

        $this->assertSame(2_000_000, $reclaimed);

        foreach ($clipPaths as $path) {
            $this->assertFalse(Storage::disk('local')->exists($path), "Clip {$path} should be gone.");
        }
    }

    public function test_purging_keeps_the_final_video_and_locked_references(): void
    {
        $project = $this->exportedProject();

        $finalPath = $project->finalAsset->path;
        $referencePath = $project->assets()
            ->where('type', AssetType::CharacterReference)->firstOrFail()->path;

        $this->retention->purge($project);

        // These are the two things NFR-7 explicitly says to keep.
        $this->assertTrue(Storage::disk('local')->exists($finalPath), 'The exported video must survive.');
        $this->assertTrue(Storage::disk('local')->exists($referencePath), 'Locked references must survive.');
        $this->assertNotNull($project->fresh()->finalAsset);
    }

    public function test_purged_shots_are_visibly_purged_and_block_export(): void
    {
        $project = $this->exportedProject();

        $this->retention->purge($project);
        $project->refresh();

        foreach ($project->shots as $shot) {
            $this->assertSame(ShotStatus::Purged, $shot->status);
        }

        // Exporting from deleted clips would fail deep inside FFmpeg. It must be
        // refused up front, with a reason that says what happened.
        $reason = app(ProjectStateMachine::class)->exportBlockedReason($project);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('purged', $reason);
    }

    public function test_purged_shots_are_picked_up_by_a_re_render(): void
    {
        $project = $this->exportedProject();
        $this->retention->purge($project);

        $queued = app(PipelineRunner::class)->renderShots($project->fresh());

        $this->assertSame(2, $queued, 'A re-render must include purged shots.');
    }

    public function test_spend_history_survives_a_purge(): void
    {
        $project = $this->exportedProject();

        $project->usageRecords()->create([
            'asset_id' => $project->assets()->where('type', AssetType::ShotClip)->firstOrFail()->id,
            'provider' => 'fake', 'operation' => 'video.clip',
            'units' => 8, 'cost_usd' => 0.80, 'outcome' => 'succeeded',
        ]);

        $this->retention->purge($project);

        // Reclaiming disk must never rewrite what the project cost.
        $this->assertEqualsWithDelta(0.80, $project->fresh()->spentUsd(), 0.0001);
    }

    public function test_deleting_a_project_removes_its_files_from_disk(): void
    {
        $project = $this->exportedProject();
        $directory = $project->storageDirectory();

        $this->assertNotEmpty(Storage::disk('local')->allFiles($directory));

        $project->delete();

        // The FK cascade drops the rows; without an explicit sweep the files
        // would sit on disk forever.
        $this->assertEmpty(Storage::disk('local')->allFiles($directory));
    }

    public function test_the_dashboard_shows_storage_used(): void
    {
        $user = User::factory()->create();
        $project = $this->exportedProject($user);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Storage')
            ->assertSee('Purge intermediate clips');
    }

    public function test_the_purge_endpoint_refuses_when_blocked(): void
    {
        $user = User::factory()->create();
        $project = $this->exportedProject($user);
        $project->forceFill(['exported_at' => null])->save();

        $this->actingAs($user)
            ->post(route('pipeline.purge', $project))
            ->assertRedirect();

        // Nothing deleted.
        $this->assertSame(2, $project->fresh()->assets()->where('type', AssetType::ShotClip)->count());
    }

    public function test_the_command_reports_without_deleting_on_a_dry_run(): void
    {
        $project = $this->exportedProject();

        $this->artisan('studio:purge-intermediates', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(2, $project->fresh()->assets()->where('type', AssetType::ShotClip)->count());
    }

    public function test_the_command_purges_eligible_projects(): void
    {
        $project = $this->exportedProject();

        $this->artisan('studio:purge-intermediates')->assertSuccessful();

        $this->assertSame(0, $project->fresh()->assets()->where('type', AssetType::ShotClip)->count());
    }

    public function test_human_bytes_reads_sensibly(): void
    {
        $this->assertSame('0 B', $this->retention->humanBytes(0));
        $this->assertSame('512 B', $this->retention->humanBytes(512));
        $this->assertSame('1 KB', $this->retention->humanBytes(1024));
        $this->assertSame('1.5 MB', $this->retention->humanBytes(1_572_864));
    }

    /**
     * A project that has been exported: two rendered shots with clips, one
     * locked character reference, and a final video — all backed by real files.
     */
    protected function exportedProject(?User $user = null): Project
    {
        $project = Project::factory()
            ->for($user ?? User::factory())
            ->status(ProjectStatus::ExportReady)
            ->create();

        $scene = Scene::factory()->for($project)->create();

        foreach ([1, 2] as $i) {
            $clip = $this->fileAsset($project, AssetType::ShotClip, 1_000_000, 'mp4');

            Shot::factory()->rendered()->for($project)->for($scene)->create([
                'sequence' => $i,
                'asset_id' => $clip->id,
            ]);
        }

        $reference = $this->fileAsset($project, AssetType::CharacterReference, 50_000, 'png');
        Character::factory()->for($project)->create([
            'canonical_reference_asset_id' => $reference->id,
            'locked_at' => now(),
        ]);

        $final = $this->fileAsset($project, AssetType::FinalVideo, 3_000_000, 'mp4');
        $project->forceFill(['final_asset_id' => $final->id, 'exported_at' => now()])->save();

        $this->fileAsset($project, AssetType::NarrationTrack, 100_000, 'wav');

        return $project->fresh();
    }

    protected function fileAsset(Project $project, AssetType $type, int $bytes, string $extension): Asset
    {
        $path = $project->storageDirectory().'/'.$type->value.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, str_repeat('x', 16));

        return Asset::factory()->for($project)->create([
            'type' => $type,
            'path' => $path,
            'bytes' => $bytes,
        ]);
    }
}
