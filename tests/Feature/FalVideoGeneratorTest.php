<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Contracts\ProviderException;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Enums\VideoResolution;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalVideoGenerator;
use App\Services\Provider\ModelRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fal adapter, driven entirely against faked HTTP.
 *
 * These tests pin two different things and it is worth keeping them apart.
 *
 * What is CERTAIN and must never regress: the payload rules. Kling has no audio
 * toggle and renders 5 or 10 seconds only — both owner-verified against a live
 * account — so sending generate_audio, or asking for 7 seconds, is a bug this
 * suite catches for free rather than at $0.35 a time.
 *
 * What is ASSUMED: fal's field names and URL shapes, which this codebase has
 * never been able to observe. Those live in FalResponseMapper and are pinned
 * here so that when the captured shapes arrive, the diff between assumption and
 * reality shows up as a named failing test.
 */
class FalVideoGeneratorTest extends TestCase
{
    protected const KLING = 'kling-2-5-turbo-pro';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_video_model', self::KLING);
    }

    protected function generator(): FalVideoGenerator
    {
        return new FalVideoGenerator(FalClient::fromConfig(), app(ModelRegistry::class));
    }

    protected function klingRequest(float $seconds = 5.0): ClipRequest
    {
        return new ClipRequest(
            prompt: 'A calm river at dawn',
            durationSeconds: $seconds,
            aspectRatio: AspectRatio::Landscape,
            resolution: VideoResolution::Hd1080,
            seed: 4242,
            modelKey: self::KLING,
        );
    }

    /**
     * @param  array<string, mixed>  $submit
     */
    protected function fakeSubmit(array $submit = ['request_id' => 'req-1']): void
    {
        Http::fake(['queue.fal.run/*' => Http::response($submit)]);
    }

    // ---- Payload rules (owner-verified facts) ------------------------------

    public function test_kling_payload_carries_prompt_duration_and_aspect_only(): void
    {
        $this->fakeSubmit();

        $this->generator()->submitClip($this->klingRequest());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame('A calm river at dawn', $body['prompt']);
            $this->assertSame('16:9', $body['aspect_ratio']);

            // Owner-verified: Kling's schema exposes no audio toggle, and a
            // parameter a model does not accept fails the whole call.
            $this->assertArrayNotHasKey('generate_audio', $body);

            // Unconfirmed parameter names are left off deliberately.
            $this->assertArrayNotHasKey('resolution', $body);
            $this->assertArrayNotHasKey('seed', $body);

            return true;
        });
    }

    public function test_duration_is_sent_as_a_whole_number(): void
    {
        $this->fakeSubmit();

        $this->generator()->submitClip($this->klingRequest(5.0));

        // 5.0 would serialise as 5.0; a schema declaring an integer enum can
        // refuse that where it accepts 5.
        Http::assertSent(fn (Request $r) => $r->data()['duration'] === 5);
    }

    public function test_a_duration_the_model_cannot_render_never_reaches_the_wire(): void
    {
        $this->fakeSubmit();

        try {
            $this->generator()->submitClip($this->klingRequest(7.0));
            $this->fail('Expected a 7-second Kling request to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertFalse($e->retryable, 'A bad duration fails identically on every retry.');
            $this->assertStringContainsString('5 or 10', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_unsupported_mode_never_reaches_the_wire(): void
    {
        $this->fakeSubmit();

        $request = new ClipRequest(
            prompt: 'A calm river at dawn',
            durationSeconds: 5.0,
            aspectRatio: AspectRatio::Landscape,
            mode: GenerationMode::ImageToVideo,
            referenceImagePath: __FILE__,
            modelKey: self::KLING,
        );

        try {
            $this->generator()->submitClip($request);
            $this->fail('Expected image-to-video on a text-to-video model to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertStringContainsString('image_to_video', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_model_with_an_audio_toggle_is_told_to_stay_quiet(): void
    {
        $this->fakeSubmit();

        $this->generator()->submitClip(new ClipRequest(
            prompt: 'A calm river at dawn',
            durationSeconds: 5.0,
            aspectRatio: AspectRatio::Landscape,
            resolution: VideoResolution::Hd720,
            muteNativeAudio: true,
            modelKey: 'veo-3-1-fast',
        ));

        // FR-14: switched off at the provider, not stripped afterwards — a
        // stripped track has already been paid for at double the rate.
        Http::assertSent(fn (Request $r) => $r->data()['generate_audio'] === false
            && $r->data()['resolution'] === '720p');
    }

    public function test_the_endpoint_comes_from_the_requested_model_not_the_default(): void
    {
        $this->fakeSubmit();

        $this->generator()->submitClip(new ClipRequest(
            prompt: 'A calm river at dawn',
            durationSeconds: 5.0,
            aspectRatio: AspectRatio::Landscape,
            resolution: VideoResolution::Hd720,
            modelKey: 'veo-3-1-fast',
        ));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'fal-ai/veo3.1/fast'));
    }

    // ---- Queue lifecycle ---------------------------------------------------

    public function test_submit_returns_the_request_id(): void
    {
        $this->fakeSubmit(['request_id' => 'req-abc', 'status' => 'IN_QUEUE']);

        $this->assertSame('req-abc', $this->generator()->submitClip($this->klingRequest()));
    }

    public function test_a_submission_with_no_request_id_fails_permanently(): void
    {
        // fal has accepted the work and is billing for it. Retrying would pay
        // twice for a render that is already running, so this must not retry.
        $this->fakeSubmit(['queue_position' => 3]);

        try {
            $this->generator()->submitClip($this->klingRequest());
            $this->fail('Expected a submission with no request id to fail.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('billable', $e->getMessage());
        }
    }

    public function test_a_queued_status_is_reported_as_queued(): void
    {
        // The full set of status strings is pinned in FalResponseMapperTest,
        // which can vary them without fighting Http::fake — a second fake()
        // call MERGES stubs rather than replacing them, so the first matching
        // stub keeps answering for the rest of the test.
        Http::fake(['*' => Http::response(['status' => 'IN_QUEUE'])]);

        $this->assertSame(ProviderRequestStatus::InQueue, $this->generator()->checkStatus('req-1'));
    }

    public function test_a_failed_status_raises_rather_than_being_polled_forever(): void
    {
        Http::fake(['*' => Http::response(['status' => 'FAILED', 'error' => 'model exploded'])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/model exploded/');

        $this->generator()->checkStatus('req-1');
    }

    public function test_an_unrecognised_status_is_reported_rather_than_guessed_at(): void
    {
        Http::fake(['*' => Http::response(['status' => 'BANANA'])]);

        try {
            $this->generator()->checkStatus('req-1');
            $this->fail('Expected an unknown status to raise.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('BANANA', $e->getMessage());
            $this->assertTrue($e->retryable, 'An unclassified answer is far more often a blip.');
        }
    }

    public function test_fal_own_status_url_is_preferred_over_a_constructed_one(): void
    {
        Http::fake([
            'queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => Http::response([
                'request_id' => 'req-9',
                'status_url' => 'https://queue.fal.run/some/other/route/status',
            ]),
            '*' => Http::response(['status' => 'IN_PROGRESS']),
        ]);

        $generator = $this->generator();
        $generator->submitClip($this->klingRequest());
        $generator->checkStatus('req-9');

        // fal is authoritative about its own routing; following it sidesteps
        // the question of how a multi-segment model id maps onto a request URL.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://queue.fal.run/some/other/route/status');
    }

    public function test_a_cold_cache_falls_back_to_the_short_endpoint_form_after_a_404(): void
    {
        // The scenario the request id exists for: the worker that submitted has
        // restarted, so nothing is cached and the URL must be reconstructed.
        $long = 'https://queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video/requests/req-7/status';
        $short = 'https://queue.fal.run/fal-ai/kling-video/requests/req-7/status';

        Http::fake([
            $long => Http::response(['detail' => 'not found'], 404),
            $short => Http::response(['status' => 'COMPLETED']),
        ]);

        $this->assertSame(ProviderRequestStatus::Completed, $this->generator()->checkStatus('req-7'));

        Http::assertSent(fn (Request $r) => $r->url() === $short);
    }

    public function test_fetch_downloads_the_clip_and_records_what_it_cost(): void
    {
        Http::fake([
            'queue.fal.run/*' => Http::response([
                'video' => ['url' => 'https://cdn.fal.media/clip.mp4', 'content_type' => 'video/mp4'],
                'billed_cost_usd' => 0.35,
            ]),
            'cdn.fal.media/*' => Http::response('MP4BYTES'),
        ]);

        $media = $this->generator()->fetchResult('req-1');

        $this->assertFileExists($media->path);
        $this->assertSame('MP4BYTES', file_get_contents($media->path));
        $this->assertSame('video/mp4', $media->mime);
        $this->assertSame(0.35, $media->meta['actual_cost_usd']);
        $this->assertSame('https://cdn.fal.media/clip.mp4', $media->meta['source_url']);

        @unlink($media->path);
    }

    public function test_a_silent_provider_leaves_the_estimate_standing(): void
    {
        Http::fake([
            'queue.fal.run/*' => Http::response(['video' => ['url' => 'https://cdn.fal.media/clip.mp4']]),
            'cdn.fal.media/*' => Http::response('MP4BYTES'),
        ]);

        $media = $this->generator()->fetchResult('req-1');

        // Null, not 0.0. "Free" and "not reported" bill very differently, and
        // coercing the second into the first records a spend of nothing.
        $this->assertNull($media->meta['actual_cost_usd']);

        @unlink($media->path);
    }

    public function test_a_result_with_no_video_url_fails_rather_than_storing_nothing(): void
    {
        Http::fake(['*' => Http::response(['status' => 'COMPLETED', 'logs' => []])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no video URL/');

        $this->generator()->fetchResult('req-1');
    }

    public function test_an_empty_download_is_not_passed_off_as_an_asset(): void
    {
        Http::fake([
            'queue.fal.run/*' => Http::response(['video' => ['url' => 'https://cdn.fal.media/clip.mp4']]),
            'cdn.fal.media/*' => Http::response(''),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/downloaded as empty/');

        $this->generator()->fetchResult('req-1');
    }

    // ---- Error taxonomy ----------------------------------------------------

    /**
     * The classification is the point: retrying a 402 waits for money that will
     * not appear, and not retrying a 429 throws away work that would have
     * succeeded a second later.
     */
    public function test_http_failures_are_classified_for_the_queue(): void
    {
        $cases = [
            [401, 'unauthorized', ProviderFailureReason::Authentication, false],
            [402, 'balance too low', ProviderFailureReason::InsufficientCredit, false],
            [429, 'slow down', ProviderFailureReason::RateLimited, true],
            [503, 'upstream down', ProviderFailureReason::ProviderError, true],
            [422, 'bad field', ProviderFailureReason::InvalidRequest, false],
            [422, 'blocked by our content policy', ProviderFailureReason::ContentRejected, false],
        ];

        // One stub, varied by reference. Registering a new fake per iteration
        // would not work: Http::fake() merges stubs, so the 401 would go on
        // answering every later case.
        $status = 500;
        $detail = '';
        // by reference, because an arrow function would capture the values
        // as they are now rather than as the loop sets them.
        Http::fake(function () use (&$status, &$detail) {
            return Http::response(['detail' => $detail], $status);
        });

        foreach ($cases as [$status, $detail, $reason, $retryable]) {
            try {
                $this->generator()->submitClip($this->klingRequest());
                $this->fail("Expected HTTP {$status} to raise.");
            } catch (ProviderException $e) {
                $this->assertSame($reason, $e->reason, "HTTP {$status} ({$detail})");
                $this->assertSame($retryable, $e->retryable, "HTTP {$status} ({$detail})");
            }
        }
    }

    public function test_a_missing_key_is_not_silently_sent_as_an_empty_credential(): void
    {
        config()->set('studio.fal.key', '');

        $this->assertFalse(FalClient::fromConfig()->hasKey());
    }
}
