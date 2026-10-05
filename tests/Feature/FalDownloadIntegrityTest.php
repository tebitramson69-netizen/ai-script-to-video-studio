<?php

namespace Tests\Feature;

use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Integrations\Fal\FalClient;
use App\Services\Provider\DownloadUrlGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What download() refuses to hand the pipeline.
 *
 * The captured Kling result reports file_size: 12418965 and that number was
 * being ignored, so a transfer cut short was accepted as an asset. ffmpeg will
 * read some truncated mp4s without complaining, which makes this the worst class
 * of failure: a clip that exists, plays, and is wrong — after being paid for.
 *
 * The size cap is a separate concern from integrity. Disk here is a fixed
 * allowance, and one runaway response does not just lose its own asset, it takes
 * down every stage that still needs to write.
 */
class FalDownloadIntegrityTest extends TestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/fal-download-'.uniqid().'.mp4');
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    protected function client(): FalClient
    {
        return new FalClient(apiKey: 'test-key', queueUrl: 'https://queue.fal.run');
    }

    public function test_it_accepts_a_download_whose_size_matches(): void
    {
        Http::fake(['*' => fn () => Http::response('0123456789')]);

        $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path, 10);

        $this->assertSame(10, filesize($this->path));
    }

    public function test_a_truncated_download_is_refused_and_the_partial_file_removed(): void
    {
        // The provider said 12418965 bytes; ten arrived. Keeping the short file
        // is how a corrupt clip reaches the timeline.
        Http::fake(['*' => fn () => Http::response('0123456789')]);

        try {
            $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path, 12418965);
            $this->fail('A truncated download should be refused.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('Treating it as truncated', $e->getMessage());

            // Retryable: a cut transfer usually completes on a second attempt.
            $this->assertTrue($e->retryable);
            $this->assertSame(ProviderFailureReason::NetworkError, $e->reason);
        }

        $this->assertFileDoesNotExist(
            $this->path,
            'A rejected download must not leave a file behind for the next attempt to adopt.',
        );
    }

    public function test_no_reported_size_means_no_size_check(): void
    {
        // Most fal responses may not carry a size. A guard that failed closed
        // here would refuse every download it had no evidence about.
        Http::fake(['*' => fn () => Http::response('0123456789')]);

        $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path, null);

        $this->assertSame(10, filesize($this->path));
    }

    public function test_a_body_over_the_cap_is_refused(): void
    {
        config(['studio.fal.max_download_bytes' => 4]);

        Http::fake(['*' => fn () => Http::response('0123456789')]);

        try {
            $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path);
            $this->fail('An oversized download should be refused.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->path);
    }

    public function test_an_empty_download_is_still_refused(): void
    {
        Http::fake(['*' => fn () => Http::response('')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('downloaded as empty');

        $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path);
    }

    public function test_the_guard_runs_before_any_request_is_made(): void
    {
        // Order matters: a refusal that happens after the fetch has already left
        // is not a guard, it is a log line.
        $this->app->bind(DownloadUrlGuard::class, fn () => new DownloadUrlGuard(
            fn (string $host) => ['127.0.0.1'],
        ));

        Http::fake(['*' => fn () => Http::response('0123456789')]);

        try {
            $this->client()->download('https://assets.example.com/clip.mp4', $this->path);
            $this->fail('A private address should be refused.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('private or reserved', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_http_url_never_reaches_the_network(): void
    {
        Http::fake(['*' => fn () => Http::response('0123456789')]);

        try {
            $this->client()->download('http://cdn.fal.media/clip.mp4', $this->path);
            $this->fail('A plaintext URL should be refused.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_unauthorised_download_is_not_retried(): void
    {
        // download() carried a blanket retry(2) - two attempts, Laravel counts
        // total tries - so every 401 was sent twice. It does not become
        // authorised by asking again.
        Http::fake(['*' => fn () => Http::response(['detail' => 'Unauthorized'], 401)]);

        try {
            $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path);
            $this->fail('A 401 should throw.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::Authentication, $e->reason);
        }

        Http::assertSentCount(1);
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_a_server_error_is_still_retried(): void
    {
        // The counterweight: a 503 is exactly what a short wait fixes.
        Http::fake(['*' => fn () => Http::response('upstream down', 503)]);

        try {
            $this->client()->download('https://cdn.fal.media/clip.mp4', $this->path);
            $this->fail('A 503 should throw after its retries.');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        // Two, not three: retry(2) is a cap on attempts, not on retries.
        $this->assertSame(2, Http::recorded()->count(), 'A 5xx should be retried.');
    }
}
