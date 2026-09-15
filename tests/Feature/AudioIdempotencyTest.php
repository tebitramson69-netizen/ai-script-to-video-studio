<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Media\FfmpegRunner;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * NFR-3: "re-running a stage never duplicates assets or double-charges."
 *
 * @group slow
 */
class AudioIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(FfmpegRunner::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }

        Storage::fake('local');

        // Give the fake drivers a price so double-charging is measurable.
        config([
            'studio.fake_costs.tts_per_1k_chars_usd' => 0.08,
            'studio.fake_costs.music_per_minute_usd' => 0.30,
        ]);
    }

    public function test_re_running_the_audio_stage_does_not_double_charge(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $firstSpend = $project->spentUsd();
        $firstNarrationId = $project->narrationAsset()?->id;
        $firstMusicId = $project->musicAsset()?->id;

        $this->assertNotNull($firstNarrationId);
        $this->assertNotNull($firstMusicId);

        // The owner presses the button again.
        $runner->generateAudio($project->fresh());
        $project->refresh();

        $this->assertEqualsWithDelta(
            $firstSpend,
            $project->spentUsd(),
            0.0001,
            'Re-running the audio stage charged the project a second time.',
        );

        $this->assertSame($firstNarrationId, $project->narrationAsset()?->id);
        $this->assertSame($firstMusicId, $project->musicAsset()?->id);
    }

    public function test_re_running_does_not_duplicate_assets(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();
        $firstCount = $project->assets()->count();

        $runner->generateAudio($project->fresh());
        $project->refresh();

        $this->assertSame(
            $firstCount,
            $project->assets()->count(),
            'Re-running the audio stage created duplicate assets.',
        );
    }

    public function test_editing_a_scene_re_voices_only_the_changed_shot(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $shots = $project->shots()->orderBy('sequence')->get();
        $unchangedAssetId = $shots[0]->narration_asset_id;
        $changedAssetId = $shots[1]->narration_asset_id;

        // The owner rewrites the second shot's line.
        $shots[1]->forceFill(['narration_segment' => 'A completely different line of narration.'])->save();

        $runner->generateAudio($project->fresh());
        $project->refresh();

        $after = $project->shots()->orderBy('sequence')->get();

        $this->assertSame(
            $unchangedAssetId,
            $after[0]->narration_asset_id,
            'An untouched shot must not be re-voiced — that is money for nothing.',
        );
        $this->assertNotSame(
            $changedAssetId,
            $after[1]->narration_asset_id,
            'The edited shot must be re-voiced, or the video says the old line.',
        );
    }

    public function test_regenerating_narration_replaces_rather_than_stacks(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $firstTrackId = $project->narrationAsset()->id;
        $assetCount = $project->assets()->count();

        $runner->regenerateNarration($project->fresh());
        $project->refresh();

        $this->assertNotSame($firstTrackId, $project->narrationAsset()->id, 'FR-15: a forced regeneration must actually regenerate.');
        $this->assertSame($assetCount, $project->assets()->count(), 'Superseded audio should be replaced, not accumulated.');
    }

    public function test_regenerating_music_leaves_narration_alone(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $narrationId = $project->narrationAsset()->id;
        $musicId = $project->musicAsset()->id;

        $runner->regenerateMusic($project->fresh());
        $project->refresh();

        $this->assertNotSame($musicId, $project->musicAsset()->id);
        $this->assertSame($narrationId, $project->narrationAsset()->id, 'Regenerating music must not re-bill narration.');
    }

    public function test_superseded_audio_files_are_removed_from_disk(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $oldPath = $project->narrationAsset()->path;
        $this->assertTrue(Storage::disk('local')->exists($oldPath));

        $runner->regenerateNarration($project->fresh());

        $this->assertFalse(
            Storage::disk('local')->exists($oldPath),
            'Replaced audio must not linger on disk.',
        );
    }

    public function test_spend_history_survives_deleting_a_superseded_asset(): void
    {
        $project = $this->projectWithShots();
        $runner = app(PipelineRunner::class);

        $runner->generateAudio($project);
        $project->refresh();

        $spendBefore = $project->spentUsd();
        $recordsBefore = $project->usageRecords()->count();

        $runner->regenerateNarration($project->fresh());
        $project->refresh();

        // Reclaiming disk must never erase what the project actually cost.
        $this->assertGreaterThan($recordsBefore, $project->usageRecords()->count());
        $this->assertGreaterThan($spendBefore, $project->spentUsd());
    }

    protected function projectWithShots(): Project
    {
        $project = Project::factory()->budget(50.00)->create();
        $scene = Scene::factory()->for($project)->create();

        foreach ([1, 2] as $i) {
            Shot::factory()->rendered()->for($project)->for($scene)->create([
                'sequence' => $i,
                'narration_segment' => "This is narration segment number {$i} of the story.",
                'target_duration_seconds' => 8.0,
            ]);
        }

        return $project->fresh();
    }
}
