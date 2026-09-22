<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Contracts\ProviderException;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use App\Integrations\Fal\FalPayloadBuilder;
use App\Services\Provider\ModelRegistry;
use Tests\TestCase;

/**
 * What goes on the wire, and — more importantly — what does not.
 *
 * Optional parameters are opt-in per model because the two mistakes are not
 * symmetrical. Omitting one the model would have accepted gives you its
 * default. Sending one it does not accept gives you a 4xx that reads like a
 * wrong URL, which sends you looking in the wrong place.
 */
class FalPayloadBuilderTest extends TestCase
{
    protected FalPayloadBuilder $builder;

    protected ModelRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new FalPayloadBuilder;
        $this->registry = app(ModelRegistry::class);
    }

    public function test_only_declared_parameters_are_sent(): void
    {
        $payload = $this->builder->build(
            new ClipRequest(
                prompt: 'river',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Portrait,
                resolution: VideoResolution::Hd1080,
                seed: 7,
            ),
            $this->registry->video('kling-2-5-turbo-pro'),
        );

        $this->assertSame(['prompt' => 'river', 'duration' => 5, 'aspect_ratio' => '9:16'], $payload);
    }

    public function test_a_declared_parameter_is_sent(): void
    {
        $payload = $this->builder->build(
            new ClipRequest(
                prompt: 'river',
                durationSeconds: 6.0,
                aspectRatio: AspectRatio::Landscape,
                resolution: VideoResolution::Hd720,
            ),
            $this->registry->video('veo-3-1-fast'),
        );

        $this->assertSame('720p', $payload['resolution']);
        $this->assertFalse($payload['generate_audio']);
    }

    public function test_a_reference_image_travels_as_a_data_uri(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ref_').'.png';

        // A 1x1 PNG, so mime_content_type() has something real to read.
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $payload = $this->builder->build(
            new ClipRequest(
                prompt: 'river',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Landscape,
                mode: GenerationMode::ImageToVideo,
                resolution: VideoResolution::Hd720,
                referenceImagePath: $path,
            ),
            $this->registry->video('veo-3-1-fast'),
        );

        // Inline rather than uploaded: the locked character reference stays on
        // our own private disk until the moment it is used (FR-6, §14), and no
        // upload endpoint has to be guessed at.
        $this->assertStringStartsWith('data:image/png;base64,', $payload['image_url']);

        @unlink($path);
    }

    public function test_image_to_video_with_no_reference_is_refused(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no reference image/');

        $this->builder->build(
            new ClipRequest(
                prompt: 'river',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Landscape,
                mode: GenerationMode::ImageToVideo,
                resolution: VideoResolution::Hd720,
            ),
            $this->registry->video('veo-3-1-fast'),
        );
    }

    public function test_a_missing_reference_file_is_refused_before_it_costs_anything(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/missing or unreadable/');

        $this->builder->build(
            new ClipRequest(
                prompt: 'river',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Landscape,
                mode: GenerationMode::ImageToVideo,
                resolution: VideoResolution::Hd720,
                referenceImagePath: '/no/such/file.png',
            ),
            $this->registry->video('veo-3-1-fast'),
        );
    }
}
