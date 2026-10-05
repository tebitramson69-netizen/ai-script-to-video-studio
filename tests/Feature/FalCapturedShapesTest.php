<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Enums\AspectRatio;
use App\Enums\ProviderRequestStatus;
use App\Enums\VideoResolution;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalResponseMapper;
use App\Integrations\Fal\FalVideoGenerator;
use App\Services\Provider\ModelRegistry;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The lifecycle, driven by responses fal actually sent.
 *
 * Everything elsewhere in this suite uses bodies written by hand — deliberately,
 * because the odd ones are what prove the mapper tolerates a renamed key. This
 * file is the opposite: the three files under
 * tests/Fixtures/fal/kling-2-5-turbo-pro are verbatim captures from one real
 * $0.35 generation on 2026-10-04, and nothing in them is adjusted to make an
 * assertion pass. They are what moved these field names from "documented" to
 * "observed".
 *
 * Two of the real values are traps, and they are the reason this file matters
 * more than its happy path suggests:
 *
 *   metrics.inference_time: 151.28999996185303   must not become a duration
 *   video.file_size:        12418965             must not become a price
 *
 * The first would put a 2.5-minute clip in a 5-second slot. The second would
 * record $12.4 million of spend against a $10 cap. Both are declined by key
 * regexes anchored at word boundaries, and an unanchored version of either would
 * pass every other test in this suite.
 */
class FalCapturedShapesTest extends TestCase
{
    protected const KLING = 'kling-2-5-turbo-pro';

    protected const REQUEST_ID = '01a10554-1955-7d51-a728-d37eb79b2e67';

    protected FalResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_video_model', self::KLING);

        $this->mapper = new FalResponseMapper;
    }

    /**
     * @return array<string, mixed>
     */
    protected function captured(string $file): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__."/../Fixtures/fal/kling-2-5-turbo-pro/{$file}.json"),
            true,
        );
    }

    protected function generator(): FalVideoGenerator
    {
        return new FalVideoGenerator(FalClient::fromConfig(), app(ModelRegistry::class));
    }

    // ---- The submit response -----------------------------------------------

    public function test_the_real_submit_body_yields_an_id_and_fals_own_urls(): void
    {
        $body = $this->captured('01-submit');

        $this->assertSame(self::REQUEST_ID, $this->mapper->requestId($body));
        $this->assertSame(ProviderRequestStatus::InQueue, $this->mapper->status($body));

        // The shape that cost a 405 to learn: requests live under the FIRST TWO
        // segments of the model id, not under the full five-segment endpoint.
        $this->assertSame(
            'https://queue.fal.run/fal-ai/kling-video/requests/'.self::REQUEST_ID.'/status',
            $this->mapper->statusUrl($body),
        );
        $this->assertSame(
            'https://queue.fal.run/fal-ai/kling-video/requests/'.self::REQUEST_ID,
            $this->mapper->resultUrl($body),
        );
        $this->assertSame(
            'https://queue.fal.run/fal-ai/kling-video/requests/'.self::REQUEST_ID.'/cancel',
            $this->mapper->cancelUrl($body),
        );
    }

    public function test_the_reconstructed_short_url_matches_the_one_fal_returns(): void
    {
        // FalClient builds this when the cache is cold and only the id survives.
        // If the two ever disagreed, a restarted worker would fail to collect a
        // clip that had already been billed.
        $client = FalClient::fromConfig();

        $this->assertContains(
            $this->mapper->resultUrl($this->captured('01-submit')),
            $client->requestUrls('fal-ai/kling-video/v2.5-turbo/pro/text-to-video', self::REQUEST_ID),
        );
    }

    // ---- The terminal status response --------------------------------------

    public function test_the_real_terminal_body_reads_as_completed(): void
    {
        $this->assertSame(
            ProviderRequestStatus::Completed,
            $this->mapper->status($this->captured('03-status-complete')),
        );
    }

    public function test_the_urls_are_null_once_finished_and_that_is_not_an_error(): void
    {
        // Observed: fal nulls status_url, response_url and cancel_url in the
        // terminal body. Anything that re-read a result URL from here would get
        // null, so the adapter keeps the ones from the submit response.
        $body = $this->captured('03-status-complete');

        $this->assertNull($this->mapper->statusUrl($body));
        $this->assertNull($this->mapper->resultUrl($body));
        $this->assertNull($this->mapper->cancelUrl($body));
    }

    public function test_inference_time_is_not_mistaken_for_a_clip_duration(): void
    {
        // 151.29 seconds of compute for a 5-second clip. Read as a duration it
        // would stretch one shot to two and a half minutes and silently wreck
        // the narration-driven timeline.
        $body = $this->captured('03-status-complete');

        $this->assertSame(151.28999996185303, $body['metrics']['inference_time']);
        $this->assertNull($this->mapper->durationSeconds($body));
    }

    public function test_the_terminal_body_is_not_read_as_an_error(): void
    {
        // Every URL in it is null and nothing is a message. A looser
        // errorMessage() would turn a successful completion into a failure.
        $this->assertNull($this->mapper->errorMessage($this->captured('03-status-complete')));
    }

    public function test_metrics_changes_type_between_submit_and_completion(): void
    {
        // [] on submit, {inference_time: …} on completion. Documented because
        // anything treating it as consistently one or the other will break.
        $this->assertSame([], $this->captured('01-submit')['metrics']);
        $this->assertArrayHasKey('inference_time', $this->captured('03-status-complete')['metrics']);
    }

    // ---- The result response ------------------------------------------------

    public function test_the_real_result_yields_the_clip_url(): void
    {
        $this->assertSame(
            'https://v3b.fal.media/files/b/0aacfce3/aT87WQ8CmNfDB9VaD2qzl_output.mp4',
            $this->mapper->videoUrl($this->captured('04-result')),
        );
    }

    public function test_the_clip_is_served_from_a_different_host_than_the_api(): void
    {
        // Why DownloadUrlGuard exists. The asset is not on the host we hold a
        // key for, so the download target is a string out of a response body.
        $url = $this->mapper->videoUrl($this->captured('04-result'));

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringNotContainsString('queue.fal.run', $url);
    }

    public function test_file_size_is_read_as_a_size_and_never_as_a_price(): void
    {
        // The expensive near-miss. actualCostUsd() scans every numeric field in
        // the body for a cost-shaped key; file_size is the only number present.
        // Unanchored, 'file_size' would match and record $12,418,965 of spend
        // against a $10 cap - and the budget guard would then refuse everything
        // forever, having never actually spent it.
        $body = $this->captured('04-result');

        $this->assertSame(12418965, $this->mapper->fileSizeBesideUrl($body, $this->mapper->videoUrl($body)));
        $this->assertNull($this->mapper->actualCostUsd($body));
    }

    public function test_kling_reports_no_cost_so_the_estimate_must_stand(): void
    {
        // Confirmed across all three bodies: fal returns no price for this
        // model. actual_cost_usd stays null, which makes the rate in
        // config/studio.php the only number the budget cap has.
        foreach (['01-submit', '03-status-complete', '04-result'] as $file) {
            $this->assertNull(
                $this->mapper->actualCostUsd($this->captured($file)),
                "{$file} should report no cost.",
            );
        }
    }

    // ---- The whole lifecycle ------------------------------------------------

    public function test_the_adapter_walks_the_real_bodies_end_to_end(): void
    {
        $submit = $this->captured('01-submit');
        $result = $this->captured('04-result');

        // file_size is the one field a test cannot honour at full fidelity
        // without writing 12MB, so the fixture's value is swapped for the length
        // of the faked body. Everything else is verbatim, and the size check
        // itself is covered in FalDownloadIntegrityTest.
        $bytes = 'not-really-an-mp4';
        $result['video']['file_size'] = strlen($bytes);

        Http::fake([
            'queue.fal.run/fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => fn () => Http::response($submit),
            'queue.fal.run/fal-ai/kling-video/requests/*/status' => fn () => Http::response(
                $this->captured('03-status-complete'),
            ),
            'queue.fal.run/fal-ai/kling-video/requests/*' => fn () => Http::response($result),
            'v3b.fal.media/*' => fn () => Http::response($bytes),
        ]);

        $generator = $this->generator();

        $requestId = $generator->submitClip(new ClipRequest(
            prompt: 'A calm river at dawn, slow drifting mist',
            durationSeconds: 5.0,
            aspectRatio: AspectRatio::Landscape,
            resolution: VideoResolution::Hd720,
            modelKey: self::KLING,
        ));

        $this->assertSame(self::REQUEST_ID, $requestId);
        $this->assertSame(ProviderRequestStatus::Completed, $generator->checkStatus($requestId));

        $clip = $generator->fetchResult($requestId);

        $this->assertFileExists($clip->path);
        $this->assertSame($bytes, file_get_contents($clip->path));
        $this->assertSame('video/mp4', $clip->mime);

        // fal reports no price for this model, so actual_cost_usd stays null and
        // costUsd carries the estimate. The invoice is the only correction.
        $this->assertNull($clip->meta['actual_cost_usd']);
        $this->assertSame(0.35, round($clip->costUsd, 2));

        @unlink($clip->path);
    }
}
