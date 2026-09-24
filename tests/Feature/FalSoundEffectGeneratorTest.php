<?php

namespace Tests\Feature;

use App\Contracts\Data\SoundEffectRequest;
use App\Contracts\ProviderException;
use App\Contracts\SoundEffectGenerator;
use App\Enums\ProviderFailureReason;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalSoundEffectGenerator;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-scene ambience on a fal-hosted sound-effect model (FR-13).
 *
 * fal is unreachable from this codebase, so every assertion is against faked
 * HTTP: these prove the adapter's decisions, not the provider's schema. Three
 * decisions carry the weight.
 *
 * 1. The loop flag is sent only when the effect is going to repeat. The default
 *    model caps at 22 seconds against scenes that run longer, and an effect not
 *    generated to loop has a seam under the narration every time it wraps.
 * 2. The prompt field is named per model ('text' here, 'prompt' elsewhere).
 * 3. An empty description is refused before any request, because it would be
 *    paid for and would return whatever the model imagined.
 */
class FalSoundEffectGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_sfx_model', 'elevenlabs-sfx-v2');
    }

    protected function generator(): FalSoundEffectGenerator
    {
        return new FalSoundEffectGenerator(
            FalClient::fromConfig(),
            app(ModelRegistry::class),
            app(FfmpegRunner::class),
        );
    }

    protected function fakeFalReturning(float $seconds = 3.0): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sfx_').'.wav';

        app(FfmpegRunner::class)->run([
            '-f', 'lavfi',
            '-i', sprintf('anoisesrc=duration=%.3f:color=pink:sample_rate=44100', $seconds),
            '-ac', '1', '-y', $path,
        ]);

        // Closures rather than fixed responses: a faked body is a stream that can
        // only be read once, and Http::fake() MERGES stubs rather than replacing
        // them — so a test that generates twice would otherwise hit the spent
        // stream of the first one and see an empty download.
        Http::fake([
            'queue.fal.run/*/status' => fn () => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => fn () => Http::response([
                'audio' => ['url' => 'https://cdn.fal.media/effect.wav'],
            ]),
            'queue.fal.run/*' => fn () => Http::response(['request_id' => 'req-sfx-1']),
            'cdn.fal.media/*' => fn () => Http::response(file_get_contents($path)),
        ]);

        return $path;
    }

    protected function skipWithoutFfmpeg(): void
    {
        if (! app(FfmpegRunner::class)->isAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
    }

    protected function sentPayload(): array
    {
        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            if ($request->method() === 'POST' && ! str_contains($request->url(), '/status')) {
                return $request->data();
            }
        }

        return [];
    }

    public function test_the_description_is_sent_under_the_models_own_field_name(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        // 'text' on this model, not 'prompt'. One wrong field name fails the
        // whole request with a 422 that reads like a wrong URL.
        $this->generator()->generate(new SoundEffectRequest(
            description: 'steady rainfall with distant thunder',
            durationSeconds: 8.0,
        ));

        $payload = $this->sentPayload();

        $this->assertSame('steady rainfall with distant thunder', $payload['text']);
        $this->assertArrayNotHasKey('prompt', $payload);
        $this->assertSame(8.0, $payload['duration_seconds']);
    }

    public function test_the_loop_flag_is_sent_only_when_the_effect_will_actually_repeat(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        // 30-second scene against a 22-second ceiling: this one repeats, so it
        // has to be generated as a loop or the seam is audible.
        $media = $this->generator()->generate(new SoundEffectRequest('a crackling open fire', 30.0));

        $this->assertTrue($this->sentPayload()['loop']);
        $this->assertSame(22.0, $this->sentPayload()['duration_seconds']);
        $this->assertTrue($media->meta['looped_to_cover_scene']);
        $this->assertTrue($media->meta['generated_as_loop']);
    }

    public function test_a_short_scene_is_not_asked_for_a_loop(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        // Asking for a loop unconditionally would constrain the sound the model
        // produces without buying anything: this effect never wraps.
        $media = $this->generator()->generate(new SoundEffectRequest('a crackling open fire', 12.0));

        $this->assertArrayNotHasKey('loop', $this->sentPayload());
        $this->assertFalse($media->meta['looped_to_cover_scene']);
    }

    public function test_a_model_with_no_loop_support_is_never_sent_the_flag(): void
    {
        $this->skipWithoutFfmpeg();
        config()->set('studio.default_sfx_model', 'stable-audio-3-sfx');
        $this->fakeFalReturning();

        // It will still repeat — the assembler loops it — but the flag is not
        // documented on this model, and an unaccepted field fails the request.
        $media = $this->generator()->generate(new SoundEffectRequest('wind moving through tall trees', 60.0));

        $this->assertArrayNotHasKey('loop', $this->sentPayload());
        $this->assertSame('wind moving through tall trees', $this->sentPayload()['prompt']);
        $this->assertTrue($media->meta['looped_to_cover_scene']);
        $this->assertFalse($media->meta['generated_as_loop']);
    }

    public function test_registry_defaults_are_sent_but_cannot_overwrite_the_description(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        $this->generator()->generate(new SoundEffectRequest('a flowing river with birdsong', 10.0));

        $payload = $this->sentPayload();

        // prompt_influence is raised from the model's loose 0.3 default, because
        // ambience under narration wants the sound asked for rather than a
        // creative interpretation of it.
        $this->assertSame(0.6, $payload['prompt_influence']);
        $this->assertSame('a flowing river with birdsong', $payload['text']);
    }

    public function test_an_empty_description_is_refused_before_anything_is_spent(): void
    {
        Http::fake();

        try {
            $this->generator()->generate(new SoundEffectRequest('   ', 10.0));
            $this->fail('An empty cue reached the provider.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
        }

        Http::assertNothingSent();
    }

    public function test_it_is_costed_per_effect_not_per_second(): void
    {
        $this->skipWithoutFfmpeg();

        // Flat per effect, so a 3-second one-shot and a 22-second ambience cost
        // the same. The budget question for SFX is how many scenes, not how long.
        // Re-faked between calls because a faked response body is a stream that
        // can only be read once.
        $this->fakeFalReturning(2.0);
        $short = $this->generator()->generate(new SoundEffectRequest('a crackling open fire', 3.0));
        $long = $this->generator()->generate(new SoundEffectRequest('a crackling open fire', 22.0));

        $this->assertSame(0.0194, $short->costUsd);
        $this->assertSame(0.0194, $long->costUsd);
        $this->assertSame(0.0194, $this->generator()->costPerEffectUsd());
    }

    public function test_the_duration_is_measured_from_the_file(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning(3.0);

        // Asked for 20 seconds, given 3. The assembler decides how many times an
        // effect repeats across its scene from this figure, so a requested length
        // recorded as a real one would loop the wrong number of times.
        $media = $this->generator()->generate(new SoundEffectRequest('night insects and distant frogs', 20.0));

        $this->assertEqualsWithDelta(3.0, $media->durationSeconds, 0.3);
        $this->assertSame(20.0, $media->meta['requested_duration_seconds']);
    }

    public function test_a_model_with_no_endpoint_is_refused_rather_than_called(): void
    {
        Http::fake();
        config()->set('studio.default_sfx_model', 'fake');

        try {
            $this->generator()->generate(new SoundEffectRequest('a busy open-air market', 10.0));
            $this->fail('A model with no endpoint was called anyway.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('declares no fal endpoint', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_submit_with_no_request_id_is_permanent_because_the_work_may_be_billing(): void
    {
        Http::fake(['queue.fal.run/*' => Http::response(['queue_position' => 2])]);

        try {
            $this->generator()->generate(new SoundEffectRequest('city traffic with distant horns', 10.0));
            $this->fail('A submit with no request id was treated as recoverable.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
        }
    }

    public function test_it_resolves_through_the_container(): void
    {
        config()->set('studio.sound_effect_generator', 'fal');

        $this->assertInstanceOf(FalSoundEffectGenerator::class, app(SoundEffectGenerator::class));
    }
}
