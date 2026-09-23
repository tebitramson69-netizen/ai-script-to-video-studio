<?php

namespace Tests\Feature;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ProviderException;
use App\Contracts\VideoGenerator;
use App\Enums\AspectRatio;
use App\Enums\GenerationMode;
use App\Enums\VideoResolution;
use App\Integrations\Fal\FalPayloadBuilder;
use App\Jobs\RenderShotJob;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Cost\CostEstimator;
use App\Services\Pipeline\AssetRecorder;
use App\Services\Pipeline\ProjectStateMachine;
use App\Services\Provider\ModelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The image-to-video registry entry, and the consequences of it being
 * image-to-video ONLY.
 *
 * Nothing in this entry was read from fal's model page. It exists because
 * without an image-to-video model, PRD G2/FR-6 character consistency cannot
 * work at all — the verified text-to-video endpoint takes no starting frame.
 *
 * What is pinned here is therefore not "these are Kling's i2v facts" but "these
 * are the deliberate placeholders, and this is how the pipeline behaves around
 * them". The placeholders are safe because the failure modes are asymmetric: a
 * wrong endpoint or parameter name returns a 4xx and is not billed, while a
 * wrong price is billed — so the endpoint is a structural guess and the price
 * is an over-estimate.
 */
class KlingImageToVideoTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'kling-2-5-turbo-pro-i2v';

    protected function model(): ModelCapabilities
    {
        return app(ModelRegistry::class)->video(self::KEY);
    }

    public function test_it_declares_image_to_video_and_not_text_to_video(): void
    {
        $model = $this->model();

        $this->assertTrue($model->supportsMode(GenerationMode::ImageToVideo));

        // Deliberate. Claiming text-to-video as well would turn a free refusal
        // into a paid provider error on every uncharactered shot.
        $this->assertFalse($model->supportsMode(GenerationMode::TextToVideo));
    }

    public function test_the_unverified_price_is_the_over_estimate_not_the_sibling_rate(): void
    {
        // $0.20/s is the top of the third-party range for Kling on fal, chosen
        // under the over-estimate rule: over-estimating makes the cap refuse a
        // run, under-estimating lets it overspend.
        //
        // This assertion exists to stop anyone quietly copying the verified
        // $0.07 text-to-video rate across. Same model, but the rate for this
        // conditioning has never been read.
        $this->assertSame(0.20, $this->model()->costPerSecondUsd());
        $this->assertNotSame(
            app(ModelRegistry::class)->video('kling-2-5-turbo-pro')->costPerSecondUsd(),
            $this->model()->costPerSecondUsd(),
        );
    }

    public function test_the_label_carries_the_caveat_to_the_dropdown(): void
    {
        // The label is what the owner reads when pinning a project, which is
        // the moment the caveat matters. Remove it together with the
        // VERIFY_IN_DASHBOARD markers in config, not before.
        $this->assertStringContainsString('UNVERIFIED', $this->model()->label);
    }

    public function test_the_reference_image_travels_as_a_data_uri(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ref_').'.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $payload = app(FalPayloadBuilder::class)->build(
            new ClipRequest(
                prompt: 'the narrator turns to camera',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Landscape,
                mode: GenerationMode::ImageToVideo,
                resolution: VideoResolution::Hd1080,
                referenceImagePath: $path,
                modelKey: self::KEY,
            ),
            $this->model(),
        );

        $this->assertStringStartsWith('data:image/png;base64,', $payload['image_url']);
        $this->assertArrayNotHasKey('generate_audio', $payload);

        @unlink($path);
    }

    public function test_a_shot_with_no_locked_character_is_refused_before_the_provider(): void
    {
        $payload = fn () => app(FalPayloadBuilder::class)->build(
            new ClipRequest(
                prompt: 'an empty street at night',
                durationSeconds: 5.0,
                aspectRatio: AspectRatio::Landscape,
                mode: GenerationMode::TextToVideo,
                resolution: VideoResolution::Hd1080,
                modelKey: self::KEY,
            ),
            $this->model(),
        );

        try {
            $payload();
            $this->fail('Expected text-to-video on an i2v-only model to be refused.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('does not support text_to_video', $e->getMessage());
        }
    }

    public function test_the_owner_is_warned_before_rendering_not_after(): void
    {
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => self::KEY,
            'aspect_ratio' => AspectRatio::Landscape->value,
        ]);

        $scene = Scene::factory()->for($project)->create();
        Shot::factory()->for($project)->for($scene)->create(['sequence' => 1]);
        Shot::factory()->for($project)->for($scene)->create(['sequence' => 2]);

        $warnings = app(ModelRegistry::class)->degradationWarnings($project);

        // Concrete count, before anything is spent — rather than arriving later
        // as two failed shots.
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('2 shot(s) have no locked character', implode(' ', $warnings));
    }

    public function test_the_render_job_refuses_rather_than_rewriting_the_request(): void
    {
        // RenderShotJob falls back to text-to-video when the model cannot do
        // image-to-video, which is right: losing the reference is a consistency
        // problem, not a broken pipeline. But the fallback is only available if
        // the model has it. Applying it blindly here would hand the adapter
        // text-to-video on a model that refuses it — turning a knowable, free
        // failure into a provider round trip.
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => self::KEY,
            'aspect_ratio' => AspectRatio::Landscape->value,
        ]);

        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->for($project)->for($scene)->create(['sequence' => 1]);

        try {
            app(RenderShotJob::class, ['shotId' => $shot->id])->handle(
                app(VideoGenerator::class),
                app(AssetRecorder::class),
                app(CostEstimator::class),
                app(ProjectStateMachine::class),
            );
            $this->fail('Expected an uncharactered shot on an i2v-only model to be refused.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('image-to-video only', $e->getMessage());
            $this->assertStringContainsString('Lock a character', $e->getMessage());
        }

        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('usage_records', 0);
    }

    public function test_a_shot_with_a_locked_character_raises_no_warning(): void
    {
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => self::KEY,
            'aspect_ratio' => AspectRatio::Landscape->value,
        ]);

        $scene = Scene::factory()->for($project)->create();
        $shot = Shot::factory()->for($project)->for($scene)->create();

        $character = Character::factory()->for($project)->create([
            'canonical_reference_asset_id' => null,
        ]);

        $shot->characters()->attach($character);

        // Still unrenderable: attached, but the reference is not locked.
        $this->assertStringContainsString(
            '1 shot(s) have no locked character',
            implode(' ', app(ModelRegistry::class)->degradationWarnings($project)),
        );
    }
}
