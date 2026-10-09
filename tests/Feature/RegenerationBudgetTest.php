<?php

namespace Tests\Feature;

use App\Contracts\SpeechSynthesizer;
use App\Enums\AssetType;
use App\Exceptions\BudgetExceededException;
use App\Jobs\GenerateNarrationJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use App\Services\Media\FfmpegRunner;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A refused regeneration must leave the project exactly as it found it.
 *
 * FR-15 regenerations are forced: they redo work that already exists. Two
 * things followed from that and neither was covered.
 *
 * GenerateNarrationJob discarded every narration asset BEFORE asserting the
 * cap, so a refused regeneration deleted audio the owner had paid for and
 * generated no replacement. Latent until the per-request billing fix, which
 * took a five-shot narration from $0.0031 to $0.10 and moved it 32x closer to
 * any cap worth having.
 *
 * And the three regenerate* entry points gated on estimateRemainingRun(), which
 * answers "what is left to do". After a finished run the answer is nothing, so
 * they each saw $0.00 and authorised a full re-synthesis. The job's own check
 * caught it a moment later — after the delete.
 *
 * @group slow
 */
class RegenerationBudgetTest extends TestCase
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
            'studio.fake_costs.tts_per_1k_chars_usd' => 0.02,
            'studio.fake_costs.music_per_minute_usd' => 0.30,
            'studio.fake_costs.sfx_per_second_usd' => 0.002,
        ]);
    }

    public function test_a_refused_narration_regeneration_keeps_the_audio_it_was_paid_for(): void
    {
        $project = $this->projectWithNarration();

        $assetIds = $project->shots->pluck('narration_asset_id')->filter()->values();
        $this->assertCount(2, $assetIds, 'Setup failed: the shots have no narration to lose.');

        // Leave no headroom at all.
        $project->forceFill(['budget_cap_usd' => $project->spentUsd()])->save();

        try {
            (new GenerateNarrationJob($project->getKey(), force: true))->handle(
                app(SpeechSynthesizer::class),
                app(AssetRecorder::class),
                app(CostEstimator::class),
                app(FfmpegRunner::class),
            );
            $this->fail('A forced regeneration over the cap was allowed to run.');
        } catch (BudgetExceededException) {
            // Expected.
        }

        foreach ($assetIds as $id) {
            $this->assertDatabaseHas('assets', ['id' => $id]);
        }

        $this->assertNotNull(
            $project->fresh()->narrationAsset(),
            'The combined narration track was destroyed by a regeneration that then refused.',
        );
    }

    public function test_regenerating_narration_is_gated_on_the_forced_charge(): void
    {
        $project = $this->projectWithNarration();

        // Faked only now: the setup above needs generateAudio()'s chain to
        // actually run, and QUEUE_CONNECTION is sync under test.
        Queue::fake();
        $this->capWithOneCentHeadroom($project);

        // estimateRemainingRun() sees a finished project and reports $0.00.
        // The forced charge is two shots at $0.02.
        $this->expectException(BudgetExceededException::class);

        app(PipelineRunner::class)->regenerateNarration($project->fresh());
    }

    public function test_regenerating_sound_effects_is_gated_on_the_forced_charge(): void
    {
        // The cue is set BEFORE the audio stage, so the scene ends up with a
        // real effect asset. That is the whole point: the old gate selected on
        // whereDoesntHave(assets), so it only priced effects that did not exist
        // yet, and a scene that already HAS one read as $0.00 to regenerate.
        // Cueing afterwards would make this test pass either way.
        config(['studio.fake_costs.sfx_per_second_usd' => 0.05]);

        $project = $this->projectWithNarration(cue: 'a flowing river with birdsong');

        $this->assertNotNull(
            $project->scenes()->first()->assets()->where('type', AssetType::SoundEffect)->first(),
            'Setup failed: the scene has no effect to regenerate.',
        );

        $this->capWithOneCentHeadroom($project);

        Queue::fake();

        $this->expectException(BudgetExceededException::class);

        app(PipelineRunner::class)->regenerateSoundEffects($project->fresh());
    }

    public function test_regenerating_music_is_gated_on_the_forced_charge(): void
    {
        $project = $this->projectWithNarration();

        // Faked only now: the setup above needs generateAudio()'s chain to
        // actually run, and QUEUE_CONNECTION is sync under test.
        Queue::fake();
        $this->capWithOneCentHeadroom($project);

        $this->expectException(BudgetExceededException::class);

        app(PipelineRunner::class)->regenerateMusic($project->fresh());
    }

    public function test_a_refused_shot_regeneration_leaves_the_shot_alone(): void
    {
        Queue::fake();
        config(['studio.video_models.fake.cost_per_second_usd' => 1.00]);

        $project = Project::factory()->budget(0.01)->create();
        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->rendered()->for($project)->for($scene)->create([
            'sequence' => 1,
            'target_duration_seconds' => 5.0,
            'prompt' => 'a riverbank at dawn',
        ]);

        $seedBefore = $shot->seed;
        $statusBefore = $shot->status;

        try {
            app(PipelineRunner::class)->regenerateShot($project, $shot->fresh(), 'a different prompt');
            $this->fail('A shot regeneration over the cap was allowed to run.');
        } catch (BudgetExceededException) {
            // Expected.
        }

        $shot->refresh();

        // Nothing was touched, so the owner can raise the cap and try again
        // against the clip they still have rather than an invalidated one.
        $this->assertSame($statusBefore, $shot->status);
        $this->assertSame($seedBefore, $shot->seed);
        $this->assertSame('a riverbank at dawn', $shot->prompt);
    }

    /**
     * A cap strictly above what is already spent, with one cent of headroom.
     *
     * budget_cap_usd is decimal(8,2) while spentUsd() carries four places, so
     * the obvious "spent + 0.001" rounds BELOW spent and the project is over
     * its cap before any estimate is consulted. Three of these tests passed
     * that way at first — on already-spent money, never reaching the forced
     * charge they exist to measure.
     */
    protected function capWithOneCentHeadroom(Project $project): void
    {
        $cap = (ceil($project->spentUsd() * 100) / 100) + 0.01;

        $project->forceFill(['budget_cap_usd' => $cap])->save();

        // The premise, asserted rather than assumed: with nothing left to do,
        // the remaining-run estimate is what the old gate saw. If this ever
        // stops being ~$0.00 these tests stop testing what they claim to.
        $this->assertLessThan(
            0.001,
            app(CostEstimator::class)->estimateRemainingRun($project->fresh())->estimatedUsd(),
            'The remaining-run estimate is not zero, so this test no longer isolates the forced charge.',
        );

        $this->assertFalse(
            app(CostEstimator::class)->estimateRemainingRun($project->fresh())->exceedsBudget(),
            'The project is already over its cap, so any refusal proves nothing about forced pricing.',
        );
    }

    protected function projectWithNarration(?string $cue = null): Project
    {
        $project = Project::factory()->budget(50.00)->create();
        $scene = Scene::factory()->for($project)->create(
            $cue === null ? [] : ['sfx_cue' => $cue],
        );

        foreach ([1, 2] as $i) {
            Shot::factory()->rendered()->for($project)->for($scene)->create([
                'sequence' => $i,
                'narration_segment' => "This is narration segment number {$i} of the story.",
                'target_duration_seconds' => 8.0,
            ]);
        }

        app(PipelineRunner::class)->generateAudio($project->fresh());

        $project = $project->fresh()->load('shots');

        $this->assertNotNull(
            $project->assets()->where('type', AssetType::NarrationTrack)->first(),
            'Setup failed: no narration was generated.',
        );

        return $project;
    }
}
