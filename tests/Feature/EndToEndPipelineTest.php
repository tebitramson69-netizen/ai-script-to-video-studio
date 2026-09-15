<?php

namespace Tests\Feature;

use App\Contracts\VideoGenerator;
use App\Enums\AspectRatio;
use App\Enums\AssetType;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Cost\CostEstimator;
use App\Services\Media\FfmpegRunner;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Pipeline\ProjectStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PRD §18, Phase 1 "Definition of Done":
 *
 *   given a 6–12 scene script, the system produces a single downloadable .mp4
 *   where every scene has a clip in correct order; one narration track plays
 *   over the whole video; one music track plays ducked under narration; the file
 *   plays in a standard player; and the owner saw an estimated cost and stayed
 *   within the budget cap.
 *
 * Runs against the local fake drivers, so it needs no provider access — but it
 * does need ffmpeg, and it renders real media.
 *
 * @group slow
 */
class EndToEndPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(FfmpegRunner::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed on this machine.');
        }

        Storage::fake('local');
    }

    public function test_a_script_becomes_a_playable_narrated_mp4(): void
    {
        $user = User::factory()->create();

        $project = Project::factory()->for($user)->create([
            'title' => 'Folk Tale',
            'aspect_ratio' => AspectRatio::Landscape,
            'budget_cap_usd' => 15.00,
            'status' => ProjectStatus::Draft,
            'script' => $this->script(),
        ]);

        $runner = app(PipelineRunner::class);
        $stateMachine = app(ProjectStateMachine::class);

        // ── 1. Script → scenes + cast (FR-1, FR-3, FR-4) ──────────────────
        $runner->parseScript($project);
        $project->refresh();

        $this->assertSame(ProjectStatus::ScriptReady, $project->status);
        $this->assertGreaterThanOrEqual(6, $project->scenes()->count(), 'A 6–12 scene script should yield at least 6 scenes.');
        $this->assertGreaterThan(0, $project->characters()->count(), 'FR-4: named characters should be detected.');

        // ── 2. Reference candidates, one locked per character (FR-5, FR-6) ─
        $runner->generateCharacterCandidates($project);
        $project->refresh();

        foreach ($project->characters as $character) {
            $candidates = $project->assets()
                ->where('type', AssetType::CharacterReference)->get()
                ->filter(fn ($a) => ($a->meta['character_id'] ?? null) === $character->id);

            $this->assertCount(3, $candidates, "FR-5: {$character->name} should have 3 candidates.");

            $character->forceFill([
                'canonical_reference_asset_id' => $candidates->first()->id,
                'locked_at' => now(),
            ])->save();

            $stateMachine->characterReferenceLocked($project->fresh(), $character);
        }

        $this->assertSame(ProjectStatus::CharactersReady, $project->fresh()->status);

        // ── 3. Shot planning, free (FR-8, FR-16, FR-17) ───────────────────
        $runner->planShots($project->fresh());
        $project->refresh();

        $this->assertSame(ProjectStatus::ScenesReady, $project->status);
        $this->assertGreaterThanOrEqual(
            $project->scenes()->count(),
            $project->shots()->count(),
            'Every scene needs at least one shot; long scenes split into more.',
        );

        $clipLengths = app(VideoGenerator::class)->supportedClipLengths();
        foreach ($project->shots as $shot) {
            $this->assertContains(
                (float) $shot->target_duration_seconds,
                $clipLengths,
                'FR-16: every shot must be bought at a clip length the model supports.',
            );
            $this->assertGreaterThanOrEqual(
                (float) $shot->narration_duration_seconds,
                (float) $shot->target_duration_seconds,
                'FR-16: a clip must never be shorter than the narration it carries.',
            );
        }

        // ── 4. The owner sees a cost before spending (NFR-4) ──────────────
        $estimate = app(CostEstimator::class)->estimateRemainingRun($project);
        $this->assertFalse($estimate->exceedsBudget());

        // ── 5. Render every shot (FR-9) ───────────────────────────────────
        $runner->renderShots($project);
        $project->refresh();

        $this->assertSame(ProjectStatus::ShotsReady, $project->status);
        $this->assertSame(0, $project->unrenderedShotCount());

        // ── 6. Narration + music (FR-12, FR-13, FR-16) ────────────────────
        $runner->generateAudio($project);
        $project->refresh();

        $this->assertSame(ProjectStatus::VoiceReady, $project->status);
        $this->assertNotNull($project->narrationAsset(), 'One narration track for the whole video.');
        $this->assertNotNull($project->musicAsset(), 'One music track.');

        foreach ($project->shots as $shot) {
            $this->assertNotNull(
                $shot->narration_asset_id,
                'FR-16: each shot needs its own measured narration to act as the master clock.',
            );
        }

        // ── 7. Export (FR-19, FR-20, FR-21) ───────────────────────────────
        $this->assertNull($stateMachine->exportBlockedReason($project));

        $runner->export($project);
        $project->refresh();

        $this->assertSame(ProjectStatus::ExportReady, $project->status);

        $final = $project->finalAsset;
        $this->assertNotNull($final, 'A single downloadable .mp4 must exist.');
        $this->assertSame('video/mp4', $final->mime);
        $this->assertTrue($final->exists());

        // ── 8. The file is a real, playable, narrated video ───────────────
        $path = $final->absolutePath();
        $probe = shell_exec(
            'ffprobe -v error -show_entries stream=codec_type,codec_name -of csv=p=0 '
            .escapeshellarg($path)
        );

        $this->assertStringContainsString('video', (string) $probe);
        $this->assertStringContainsString('audio', (string) $probe, 'The export must carry the narration mix.');
        $this->assertStringContainsString('h264', (string) $probe);

        // FR-18: the timeline is the sum of the narration durations, because
        // each clip is trimmed or held to its narration.
        $expected = (float) $project->shots->sum(fn ($s) => $s->timelineDurationSeconds());
        $this->assertEqualsWithDelta(
            $expected,
            app(FfmpegRunner::class)->durationSeconds($path),
            1.0,
            'Final runtime should match the narration-driven timeline.',
        );

        // ── 9. Spend stayed inside the cap, and every call was logged ─────
        $this->assertLessThanOrEqual((float) $project->budget_cap_usd, $project->spentUsd());
        $this->assertGreaterThan(0, $project->usageRecords()->count(), 'NFR-5: every generation is logged.');
    }

    public function test_the_owner_can_download_the_finished_video(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create([
            'script' => $this->script(),
            'status' => ProjectStatus::Draft,
        ]);

        $runner = app(PipelineRunner::class);
        $runner->parseScript($project);
        $runner->planShots($project->fresh());
        $runner->renderShots($project->fresh());
        $runner->generateAudio($project->fresh());
        $runner->export($project->fresh());

        $project->refresh();
        $this->assertNotNull($project->finalAsset);

        $this->actingAs($user)
            ->get(route('assets.download', [$project, $project->finalAsset]))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    protected function script(): string
    {
        return <<<'TXT'
        Ngozi walked to the river at dawn. The water was calm and the birds were singing softly above her.

        She filled her clay pot slowly, watching the mist rise off the surface of the water.

        Deep in the forest, Ngozi met Tembe, an old hunter with a scarred face and tired eyes.

        Tembe warned her that danger waited beyond the hills, where the old road had been washed away.

        Ngozi thanked him and walked on, though her heart beat faster with every step she took.

        That night a storm broke over the village. Thunder rolled across the dark sky.

        Ngozi ran through the trees, afraid, as the rain soaked through her wrapper.

        By morning the storm had passed, and the village woke to a bright and quiet sky.
        TXT;
    }
}
