<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Integrations\Fal\FalPayloadBuilder;
use App\Services\Provider\ModelRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

    public function test_it_can_probe_an_image_to_video_model(): void
    {
        // The endpoint whose shapes are least certain is the one the probe
        // could not reach at all: it built a text-to-video request, which an
        // image-to-video-only model refuses before any URL is tried.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro-i2v',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('image-to-video')
            ->expectsOutputToContain('image_url')
            ->assertSuccessful();
    }

    public function test_the_inlined_image_is_abbreviated_in_the_printed_payload(): void
    {
        // A reference image inlines as hundreds of kilobytes of base64.
        // Printing it verbatim would bury the fields worth reading; the file
        // written to disk keeps the whole thing.
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro-i2v',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('chars]')
            ->assertSuccessful();
    }

    public function test_a_missing_reference_image_is_refused(): void
    {
        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro-i2v',
            '--image' => '/no/such/reference.png',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
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

    public function test_a_rejection_is_written_to_disk_not_only_to_the_terminal(): void
    {
        // The probe is a one-shot against a funded account, and a rejection is
        // the most useful thing it can return: fal charges nothing for a 4xx,
        // and the body names the field and the type it expected. Leaving that
        // body in scrollback means the one piece of evidence the run produced
        // can be lost by closing a window.
        config(['studio.fal.key' => 'test-key']);

        $out = storage_path('framework/testing/fal-capture-rejected');
        File::deleteDirectory($out);

        Http::fake([
            '*' => fn () => Http::response([
                'detail' => [[
                    'loc' => ['body', 'duration'],
                    'msg' => 'Input should be a valid string',
                    'type' => 'string_type',
                ]],
            ], 422),
        ]);

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--out' => $out,
        ])
            ->expectsConfirmation('Spend that and capture the shapes?', 'yes')
            ->assertFailed();

        $rejections = File::glob($out.'/99-rejected-*.json');

        $this->assertCount(1, $rejections, 'The 422 body was not persisted.');

        $saved = json_decode(File::get($rejections[0]), true);

        $this->assertSame(422, $saved['status']);
        $this->assertSame('duration', $saved['body']['detail'][0]['loc'][1]);
        $this->assertSame('Input should be a valid string', $saved['body']['detail'][0]['msg']);

        // The request that caused it has to be beside it, or the rejection
        // names a field without saying what was sent for it.
        $this->assertFileExists($out.'/00-request-sent.json');

        File::deleteDirectory($out);
    }

    public function test_the_probe_follows_fals_own_status_and_result_urls(): void
    {
        // The bug this closes cost a real $0.35. The command built
        // {queue}/{model}/requests/{id}/status by hand, which the live provider
        // answered with 405 - while the adapter, which follows the status_url
        // fal returns, would have worked. A probe that fails where the code it
        // validates succeeds reports a fault that does not exist.
        config(['studio.fal.key' => 'test-key']);

        $out = storage_path('framework/testing/fal-capture-follows-urls');
        File::deleteDirectory($out);

        Http::fake([
            'queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => fn () => Http::response([
                'request_id' => 'req-live-1',
                'status_url' => 'https://queue.fal.run/fal-ai/kling-video/requests/req-live-1/status',
                'response_url' => 'https://queue.fal.run/fal-ai/kling-video/requests/req-live-1',
            ]),
            'queue.fal.run/fal-ai/kling-video/requests/req-live-1/status' => fn () => Http::response([
                'status' => 'COMPLETED',
            ]),
            'queue.fal.run/fal-ai/kling-video/requests/req-live-1' => fn () => Http::response([
                'video' => ['url' => 'https://cdn.fal.media/clip.mp4'],
            ]),
        ]);

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--out' => $out,
        ])
            ->expectsConfirmation('Spend that and capture the shapes?', 'yes')
            ->assertSuccessful();

        // The five-segment URL the old code built must never be requested.
        Http::assertNotSent(fn (Request $r) => str_contains(
            $r->url(),
            'v2.5-turbo/pro/text-to-video/requests',
        ));

        Http::assertSent(fn (Request $r) => $r->url()
            === 'https://queue.fal.run/fal-ai/kling-video/requests/req-live-1/status');

        $this->assertFileExists($out.'/04-result.json');

        File::deleteDirectory($out);
    }

    public function test_the_probe_falls_through_a_405_to_the_short_request_form(): void
    {
        // A worker that restarted holds only the id, so the URL has to be
        // reconstructed. fal answers the long form with 405, not 404 - measured
        // against the live account - so the probe must treat that as "wrong
        // shape, try the next" exactly as FalClient does.
        config(['studio.fal.key' => 'test-key']);

        $out = storage_path('framework/testing/fal-capture-405-fallthrough');
        File::deleteDirectory($out);

        $long = 'https://queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video/requests/req-live-2/status';
        $short = 'https://queue.fal.run/fal-ai/kling-video/requests/req-live-2/status';

        Http::fake([
            // No status_url in the submit body: the cold-cache case.
            'queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => fn () => Http::response([
                'request_id' => 'req-live-2',
            ]),
            $long => fn () => Http::response(['detail' => 'Method Not Allowed'], 405),
            $short => fn () => Http::response(['status' => 'COMPLETED']),
            '*' => fn () => Http::response(['video' => ['url' => 'https://cdn.fal.media/clip.mp4']]),
        ]);

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--out' => $out,
        ])
            ->expectsConfirmation('Spend that and capture the shapes?', 'yes')
            ->expectsOutputToContain('trying the next shape')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === $short);

        File::deleteDirectory($out);
    }

    public function test_a_real_refusal_is_not_mistaken_for_a_wrong_url(): void
    {
        // The counterweight: a 401 answers identically on every candidate, so
        // walking the list would just repeat the failure. It must stop and
        // report, and the body must still be captured.
        config(['studio.fal.key' => 'test-key']);

        $out = storage_path('framework/testing/fal-capture-real-refusal');
        File::deleteDirectory($out);

        Http::fake([
            'queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => fn () => Http::response([
                'request_id' => 'req-live-3',
            ]),
            '*' => fn () => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--out' => $out,
        ])
            ->expectsConfirmation('Spend that and capture the shapes?', 'yes')
            ->doesntExpectOutputToContain('trying the next shape')
            ->assertFailed();

        $this->assertNotEmpty(
            File::glob($out.'/99-rejected-*.json'),
            'The 401 body was not captured.',
        );

        File::deleteDirectory($out);
    }

    public function test_a_rejection_never_carries_the_api_key(): void
    {
        // The captured files exist to be pasted into a chat.
        config(['studio.fal.key' => 'secret-key-value']);

        $out = storage_path('framework/testing/fal-capture-redacted');
        File::deleteDirectory($out);

        Http::fake([
            '*' => fn () => Http::response(['detail' => 'rejected for secret-key-value'], 401),
        ]);

        $this->artisan('studio:capture-fal-shapes', [
            '--model' => 'kling-2-5-turbo-pro',
            '--out' => $out,
        ])
            ->expectsConfirmation('Spend that and capture the shapes?', 'yes')
            ->assertFailed();

        foreach (File::glob($out.'/*.json') as $file) {
            $this->assertStringNotContainsString('secret-key-value', File::get($file));
        }

        File::deleteDirectory($out);
    }
}
