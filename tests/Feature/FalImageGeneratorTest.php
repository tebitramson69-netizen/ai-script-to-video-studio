<?php

namespace Tests\Feature;

use App\Contracts\Data\ImageRequest;
use App\Contracts\ProviderException;
use App\Enums\AspectRatio;
use App\Enums\ProviderFailureReason;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalImageGenerator;
use App\Models\Project;
use App\Models\User;
use App\Services\Provider\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Character reference candidates (FR-5).
 *
 * Two assertions here matter more than the rest, and neither is about picture
 * quality.
 *
 * The seed, because a reference the owner locks must be reproducible (PRD
 * §10.2, NFR-5) — without it, "locked" quietly means "lucky".
 *
 * The shape, because the locked reference IS the framing for every
 * image-to-video shot built from it: Kling's image-to-video endpoint takes no
 * aspect_ratio and inherits the starting frame's. A square reference on a 16:9
 * project mis-frames the whole video at full price.
 */
class FalImageGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_image_model', 'flux-schnell');
    }

    protected function generator(): FalImageGenerator
    {
        return new FalImageGenerator(FalClient::fromConfig(), app(ModelRegistry::class));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function fakeFal(array $result = []): void
    {
        Http::fake([
            'queue.fal.run/*/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => Http::response($result ?: [
                'images' => [['url' => 'https://cdn.fal.media/ref.png', 'content_type' => 'image/png']],
                'seed' => 4242,
            ]),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-img-1']),
            'cdn.fal.media/*' => Http::response('PNGBYTES'),
        ]);
    }

    protected function request(AspectRatio $ratio = AspectRatio::Landscape, ?int $seed = 4242): ImageRequest
    {
        return new ImageRequest(
            prompt: 'Ada, mid-30s, close-up portrait, soft light',
            aspectRatio: $ratio,
            seed: $seed,
            label: 'Ada',
        );
    }

    public function test_the_project_ratio_becomes_the_models_size_preset(): void
    {
        $this->fakeFal();

        $this->generator()->generate($this->request(AspectRatio::Landscape));

        // FLUX takes a preset name, not width and height. This mapping is what
        // keeps a reference in the project's shape — and therefore keeps every
        // image-to-video shot built from it in that shape too.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'flux')) {
                return true;
            }

            return ($r->data()['image_size'] ?? null) === 'landscape_16_9';
        });
    }

    public function test_every_declared_ratio_maps_to_a_distinct_preset(): void
    {
        $model = app(ModelRegistry::class)->image('flux-schnell');

        $this->assertSame('landscape_16_9', $model->imageSizeFor(AspectRatio::Landscape));
        $this->assertSame('portrait_16_9', $model->imageSizeFor(AspectRatio::Portrait));
        $this->assertSame('square_hd', $model->imageSizeFor(AspectRatio::Square));
    }

    public function test_a_ratio_the_model_cannot_produce_is_refused_before_spending(): void
    {
        config()->set('studio.image_models.flux-schnell.image_sizes', ['16:9' => 'landscape_16_9']);
        $this->fakeFal();

        try {
            $this->generator()->generate($this->request(AspectRatio::Square));
            $this->fail('Expected an undeclared ratio to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('no image size for 1:1', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_seed_is_sent_so_a_locked_reference_can_be_reproduced(): void
    {
        $this->fakeFal();

        $this->generator()->generate($this->request(seed: 99));

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'flux')) {
                return true;
            }

            return ($r->data()['seed'] ?? null) === 99;
        });
    }

    public function test_the_providers_own_seed_is_recorded_when_none_was_supplied(): void
    {
        $this->fakeFal();

        // fal echoes the seed it chose. Recording ours instead would store a
        // null against an image that can, in fact, be reproduced.
        $media = $this->generator()->generate($this->request(seed: null));

        $this->assertSame(4242, $media->meta['seed']);

        @unlink($media->path);
    }

    public function test_the_image_is_downloaded_and_costed(): void
    {
        $this->fakeFal();

        $media = $this->generator()->generate($this->request());

        $this->assertFileExists($media->path);
        $this->assertSame('PNGBYTES', file_get_contents($media->path));
        $this->assertSame('image/png', $media->mime);
        $this->assertSame(0.008, $media->costUsd);

        // A still has no duration, and null is not the same as zero to the
        // assembler.
        $this->assertNull($media->durationSeconds);

        @unlink($media->path);
    }

    public function test_a_result_with_no_image_url_fails_rather_than_storing_nothing(): void
    {
        $this->fakeFal(['seed' => 1, 'timings' => ['inference' => 0.4]]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no image URL/');

        $this->generator()->generate($this->request());
    }

    public function test_an_image_url_nested_unexpectedly_is_still_found(): void
    {
        $this->fakeFal(['payload' => ['outputs' => [['href' => 'https://cdn.fal.media/deep.jpg']]]]);

        $media = $this->generator()->generate($this->request());

        $this->assertSame('image/jpeg', $media->mime);

        @unlink($media->path);
    }

    public function test_a_project_is_refused_when_the_image_model_cannot_do_its_ratio(): void
    {
        // Making image ratios model-specific created the same gap the video
        // companion had: a project could be created at a ratio one of its
        // models cannot produce, and only fail once the run was under way.
        config()->set('studio.image_models.flux-schnell.image_sizes', ['16:9' => 'landscape_16_9']);

        $project = Project::factory()
            ->for(User::factory())
            ->create(['aspect_ratio' => AspectRatio::Square->value, 'video_model' => 'fake']);

        $reason = app(ModelRegistry::class)->incompatibilityReason($project);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('character references', $reason);
    }

    public function test_a_submission_with_no_request_id_is_not_retried(): void
    {
        Http::fake(['queue.fal.run/*' => Http::response(['queue_position' => 1])]);

        try {
            $this->generator()->generate($this->request());
            $this->fail('Expected a submission with no request id to fail.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('billable', $e->getMessage());
        }
    }
}
