<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Integrations\Fal\FalPayloadBuilder;
use App\Services\Provider\ModelRegistry;
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

    public function test_it_warns_rather_than_silently_dropping_an_ignored_parameter(): void
    {
        // A silently dropped flag is worse than a refused one: you read the
        // captured payload, see resolution missing, and conclude the model
        // rejected it — a false finding bought with a real charge.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--resolution' => '1080p',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('--resolution is ignored')
            ->assertSuccessful();
    }

    public function test_an_undeclared_aspect_ratio_is_refused_before_spending(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--aspect' => '1:1',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('does not declare aspect ratio 1:1')
            ->assertFailed();
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

    public function test_the_probe_sends_exactly_what_the_adapter_would_send(): void
    {
        // The whole value of paying for this probe. A narrower payload would
        // prove only that the probe worked, leaving the adapter free to 4xx on
        // the first real render over a parameter that was never tested — after
        // the money meant to rule that out had been spent.
        $capabilities = app(ModelRegistry::class)->video('kling-2-5-turbo-pro');

        $expected = app(FalPayloadBuilder::class)->build(
            new ClipRequest(
                prompt: 'A calm river at dawn, slow drifting mist',
                durationSeconds: 5.0,
                aspectRatio: $capabilities->aspectRatios[0],
                resolution: $capabilities->defaultResolution,
                modelKey: $capabilities->key,
            ),
            $capabilities,
        );

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();
    }

    public function test_unconfirmed_parameters_stay_off_the_wire(): void
    {
        // Kling declares only aspect_ratio. resolution and seed have
        // unconfirmed names on that schema, and one unaccepted parameter
        // fails the whole call.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--dry-run' => true,
        ])
            ->doesntExpectOutputToContain('"resolution"')
            ->doesntExpectOutputToContain('"seed"')
            ->assertSuccessful();
    }

    public function test_minimal_bisects_by_dropping_back_to_prompt_and_duration(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--minimal' => true,
            '--dry-run' => true,
        ])
            ->doesntExpectOutputToContain('aspect_ratio')
            ->expectsOutputToContain('NOT what the adapter sends')
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
