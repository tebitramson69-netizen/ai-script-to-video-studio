<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\VideoGenerator;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use Tests\TestCase;

/**
 * The model registry is the single source of truth for limits and prices.
 * These tests pin the behaviour the rest of the pipeline relies on.
 */
class ModelCapabilitiesTest extends TestCase
{
    protected function veo(string $key = 'veo-3-1'): ModelCapabilities
    {
        return ModelCapabilities::fromConfig($key, config("studio.video_models.{$key}"));
    }

    public function test_no_model_key_contains_a_dot(): void
    {
        // Laravel resolves config paths by dot notation, so a key like
        // "veo-3.1" is read as ["veo-3"]["1"] and silently returns null —
        // every price and limit for that model vanishes without an error.
        foreach (array_keys(config('studio.video_models')) as $key) {
            $this->assertStringNotContainsString(
                '.',
                (string) $key,
                "Model key '{$key}' contains a dot and will not resolve through config().",
            );
        }
    }

    public function test_every_registered_model_builds_without_error(): void
    {
        foreach (array_keys(config('studio.video_models')) as $key) {
            $capabilities = ModelCapabilities::fromConfig($key, config("studio.video_models.{$key}"));

            $this->assertNotEmpty($capabilities->clipLengths, "Model '{$key}' declares no clip lengths.");
            $this->assertNotEmpty($capabilities->resolutions, "Model '{$key}' declares no resolutions.");
            $this->assertGreaterThanOrEqual(
                0.0,
                $capabilities->costPerSecondUsd(),
                "Model '{$key}' has no usable default rate.",
            );
        }
    }

    public function test_disabling_audio_halves_the_veo_rate(): void
    {
        $veo = $this->veo();

        // This is the whole reason the audio flag is part of the cost model.
        $this->assertSame(0.20, $veo->costPerSecondUsd(VideoResolution::Hd720, withAudio: false));
        $this->assertSame(0.40, $veo->costPerSecondUsd(VideoResolution::Hd720, withAudio: true));
        $this->assertSame(0.20, $veo->costPerSecondUsd(VideoResolution::Hd1080, withAudio: false));
    }

    public function test_four_k_is_priced_separately(): void
    {
        $veo = $this->veo();

        $this->assertSame(0.40, $veo->costPerSecondUsd(VideoResolution::Uhd4k, withAudio: false));
        $this->assertSame(0.60, $veo->costPerSecondUsd(VideoResolution::Uhd4k, withAudio: true));
    }

    public function test_costing_defaults_to_audio_off_because_every_narrated_project_disables_it(): void
    {
        $this->assertSame(0.20, $this->veo()->costPerSecondUsd());
    }

    public function test_an_unpriced_resolution_is_refused_rather_than_guessed(): void
    {
        // Silently returning 0.0 would let a run past the budget cap for free.
        $this->expectException(\InvalidArgumentException::class);

        $this->veo('veo-3-1-fast')->costPerSecondUsd(VideoResolution::Uhd4k);
    }

    public function test_veo_does_not_support_square_video(): void
    {
        $veo = $this->veo();

        $this->assertTrue($veo->supportsAspectRatio(AspectRatio::Landscape));
        $this->assertTrue($veo->supportsAspectRatio(AspectRatio::Portrait));

        // A real constraint, not a detail: a 1:1 project cannot render on Veo,
        // and the owner needs to learn that at project creation rather than
        // after paying for a failed shot.
        $this->assertFalse($veo->supportsAspectRatio(AspectRatio::Square));
    }

    public function test_veo_clip_lengths_bound_the_timing_engine(): void
    {
        $veo = $this->veo();

        $this->assertSame([5.0, 6.0, 7.0, 8.0], $veo->clipLengths);
        $this->assertSame(8.0, $veo->maxClipLengthSeconds());
        $this->assertSame(5.0, $veo->minClipLengthSeconds());
    }

    public function test_veo_supports_both_text_and_image_conditioning(): void
    {
        $veo = $this->veo();

        $this->assertTrue($veo->supportsMode(GenerationMode::TextToVideo));
        $this->assertTrue($veo->supportsMode(GenerationMode::ImageToVideo));

        // Phase 2 (FR-7). Modelled, not yet claimed.
        $this->assertFalse($veo->supportsMode(GenerationMode::ReferenceToVideo));
    }

    public function test_a_model_may_declare_one_flat_rate_instead_of_a_pricing_table(): void
    {
        $capabilities = ModelCapabilities::fromConfig('flat', [
            'resolutions' => ['720p', '1080p'],
            'cost_per_second_usd' => 0.09,
        ]);

        // Repeating one number six times invites it to drift.
        $this->assertSame(0.09, $capabilities->costPerSecondUsd(VideoResolution::Hd720));
        $this->assertSame(0.09, $capabilities->costPerSecondUsd(VideoResolution::Hd1080, withAudio: true));
    }

    public function test_the_fake_driver_estimates_from_the_registry_like_a_real_one(): void
    {
        config(['studio.video_models.fake.cost_per_second_usd' => 0.10]);

        $video = app(VideoGenerator::class);

        $request = new ClipRequest(
            prompt: 'a river at dawn',
            durationSeconds: 8.0,
            aspectRatio: AspectRatio::Landscape,
            mode: GenerationMode::TextToVideo,
            resolution: VideoResolution::Hd720,
        );

        $this->assertEqualsWithDelta(0.80, $video->estimateCostUsd($request), 0.0001);
    }

    /**
     * A guard on the economics, not the code.
     *
     * Veo 3.1 Standard at $0.20/s costs $12.80 for the PRD's 64-second
     * reference video — 85% of the default $15 cap on a clean run, leaving room
     * for roughly one regenerated shot. Since the review-and-regenerate loop is
     * a deliberate feature (FR-10, NG1) and not an accident, a default model
     * that cannot afford it is a misconfiguration.
     */
    public function test_the_default_model_leaves_room_to_regenerate(): void
    {
        $key = config('studio.default_video_model');
        $capabilities = ModelCapabilities::fromConfig($key, config("studio.video_models.{$key}"));

        $referenceSeconds = 64.0;
        $cap = (float) config('studio.budget.default_cap_usd');
        $cleanRun = $referenceSeconds * $capabilities->costPerSecondUsd();

        $this->assertLessThanOrEqual(
            $cap * 0.6,
            $cleanRun,
            sprintf(
                'Default model "%s" costs $%.2f for a 64s video against a $%.2f cap, leaving too '.
                'little for regenerations. If this is veo-3-1-fast, its rate in config/studio.php '.
                'is still the VERIFY_IN_DASHBOARD placeholder set to the Standard price — put the '.
                'real Fast rate in. Otherwise pick a cheaper default or raise the cap.',
                $key, $cleanRun, $cap,
            ),
        );
    }
}
