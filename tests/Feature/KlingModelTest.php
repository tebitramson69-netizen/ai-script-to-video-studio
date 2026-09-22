<?php

namespace Tests\Feature;

use App\Contracts\Data\ModelCapabilities;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;
use App\Services\Provider\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kling 2.5 Turbo Pro, as read from the fal model page by the project owner on
 * 22 Sep 2026. These are the only primary-source model facts the project has,
 * so they are pinned rather than left to drift.
 */
class KlingModelTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'kling-2-5-turbo-pro';

    protected function kling(): ModelCapabilities
    {
        return app(ModelRegistry::class)->video(self::KEY);
    }

    public function test_five_seconds_costs_thirty_five_cents(): void
    {
        // The headline figure from the model page: $0.35 for 5s, $0.07 per
        // additional second.
        $this->assertEqualsWithDelta(0.35, 5 * $this->kling()->costPerSecondUsd(), 0.0001);
        $this->assertEqualsWithDelta(0.70, 10 * $this->kling()->costPerSecondUsd(), 0.0001);
        $this->assertSame(0.07, $this->kling()->costPerSecondUsd());
    }

    public function test_the_rate_is_flat_across_resolutions_and_audio(): void
    {
        $kling = $this->kling();

        // No audio toggle and no resolution tier means no price dimensions —
        // unlike Veo, where turning audio off halves the rate.
        foreach ([VideoResolution::Hd720, VideoResolution::Hd1080] as $resolution) {
            foreach ([true, false] as $audio) {
                $this->assertSame(0.07, $kling->costPerSecondUsd($resolution, withAudio: $audio));
            }
        }
    }

    public function test_only_five_and_ten_second_clips_exist(): void
    {
        // Not a 5-10 range. Asking for 6, 7, 8 or 9 would be rejected.
        $this->assertSame([5.0, 10.0], $this->kling()->clipLengths);
    }

    public function test_the_planner_never_asks_for_an_unsupported_duration(): void
    {
        $project = Project::factory()->create([
            'video_model' => self::KEY,
            'aspect_ratio' => AspectRatio::Landscape,
            'budget_cap_usd' => 100,
        ]);

        // Narration that would land on 6-9 seconds if the ladder were finer.
        Scene::factory()->for($project)->create([
            'narration' => 'The old hunter walked through the long wet grass and he did not '
                .'once look back toward the burning village behind him.',
        ]);

        app(PipelineRunner::class)->planShots($project);

        foreach ($project->shots as $shot) {
            $this->assertContains(
                (float) $shot->target_duration_seconds,
                [5.0, 10.0],
                "Planned a {$shot->target_duration_seconds}s clip; Kling renders only 5s or 10s.",
            );
        }
    }

    public function test_it_exposes_no_audio_toggle(): void
    {
        // The adapter must not send generate_audio to this endpoint.
        $this->assertFalse($this->kling()->supportsNativeAudioToggle);
        $this->assertFalse($this->kling()->emitsNativeAudio);
    }

    public function test_this_endpoint_is_text_to_video_only(): void
    {
        $kling = $this->kling();

        $this->assertTrue($kling->supportsMode(GenerationMode::TextToVideo));
        $this->assertFalse($kling->supportsMode(GenerationMode::ImageToVideo));
    }

    public function test_square_projects_are_refused_because_the_ratio_is_unconfirmed(): void
    {
        $kling = $this->kling();

        $this->assertTrue($kling->supportsAspectRatio(AspectRatio::Landscape));
        $this->assertTrue($kling->supportsAspectRatio(AspectRatio::Portrait));

        // Undeclared until the model page confirms it: refusing at creation is
        // cheaper than failing after a paid render.
        $this->assertFalse($kling->supportsAspectRatio(AspectRatio::Square));
    }

    public function test_locked_characters_on_a_text_only_model_warn_loudly(): void
    {
        $project = Project::factory()->create(['video_model' => self::KEY]);
        $reference = Asset::factory()->for($project)->create();
        Character::factory()->for($project)->create([
            'canonical_reference_asset_id' => $reference->id,
            'locked_at' => now(),
        ]);

        $warnings = app(ModelRegistry::class)->degradationWarnings($project);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('text-to-video only', $warnings[0]);
        $this->assertStringContainsString('consistent', $warnings[0]);
    }

    public function test_no_warning_when_there_is_nothing_to_lose(): void
    {
        // A narrated explainer with no cast loses nothing on a text-only model.
        $project = Project::factory()->create(['video_model' => self::KEY]);

        $this->assertSame([], app(ModelRegistry::class)->degradationWarnings($project));
    }

    public function test_the_warning_reaches_the_owner_before_they_render(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create(['video_model' => self::KEY]);
        $reference = Asset::factory()->for($project)->create();
        Character::factory()->for($project)->create([
            'canonical_reference_asset_id' => $reference->id,
            'locked_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Reduced output')
            ->assertSee('text-to-video only', false);
    }

    public function test_a_sixty_second_video_stays_well_inside_the_cap(): void
    {
        $kling = $this->kling();

        // Eight scenes that each round up to a 10s clip is the expensive case
        // for this ladder: 80s bought for ~60s of narration.
        $worstCase = 8 * 10 * $kling->costPerSecondUsd();

        $this->assertEqualsWithDelta(5.60, $worstCase, 0.0001);
        $this->assertLessThan((float) config('studio.budget.default_cap_usd'), $worstCase);
    }
}
