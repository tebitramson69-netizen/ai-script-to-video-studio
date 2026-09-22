<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The diagnostic that discovers fal's payload shapes.
 *
 * Its failure mode is nastier than it looks: send a parameter the model does
 * not accept and the 4xx that comes back reads like a wrong URL rather than a
 * wrong payload — so the tool built to remove guesswork would itself become a
 * source of it, after charging for the privilege.
 */
class CaptureFalShapesCommandTest extends TestCase
{
    public function test_a_dry_run_spends_nothing_and_needs_no_key(): void
    {
        config(['studio.fal.key' => '']);

        $this->artisan('studio:capture-fal-shapes', ['--dry-run' => true])
            ->expectsOutputToContain('nothing was sent and nothing was charged')
            ->assertSuccessful();
    }

    public function test_it_does_not_send_an_audio_flag_to_a_model_without_one(): void
    {
        // Kling 2.5 Turbo Pro exposes no generate_audio in its schema.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->doesntExpectOutputToContain('generate_audio')
            ->assertSuccessful();
    }

    public function test_it_warns_rather_than_silently_dropping_an_audio_request(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--audio' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('has no audio toggle')
            ->assertSuccessful();
    }

    public function test_it_does_send_the_audio_flag_to_a_model_that_has_one(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'veo-3-1-fast',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('generate_audio')
            ->assertSuccessful();
    }

    public function test_it_omits_unconfirmed_parameters_by_default(): void
    {
        // Kling's accepted resolutions and ratios are unverified, so a first
        // probe leaves them out entirely.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->doesntExpectOutputToContain('resolution')
            ->doesntExpectOutputToContain('aspect_ratio')
            ->assertSuccessful();
    }

    public function test_it_sends_them_when_explicitly_asked(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--resolution' => '1080p',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('1080p')
            ->assertSuccessful();
    }

    public function test_it_refuses_a_duration_the_model_cannot_render(): void
    {
        // Kling does 5 or 10 and nothing between. Asking for 7 would buy an
        // error, which is exactly the spend this command exists to avoid.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--duration' => 7,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('5 or 10 second clips only')
            ->assertFailed();
    }

    public function test_it_defaults_to_the_models_cheapest_clip(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('"duration":5')
            ->assertSuccessful();
    }

    public function test_it_resolves_the_endpoint_from_the_registry(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('fal-ai/kling-video/v2.5-turbo/pro/text-to-video')
            ->assertSuccessful();
    }

    public function test_an_unknown_model_names_the_registered_ones(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'nonsense',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('kling-2-5-turbo-pro')
            ->assertFailed();
    }

    public function test_a_model_with_no_endpoint_fails_before_spending(): void
    {
        // The local fake has no endpoint; it must not be probed over HTTP.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'fake',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('declares no endpoint')
            ->assertFailed();
    }
}
