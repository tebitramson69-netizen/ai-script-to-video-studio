<?php

namespace Tests\Feature;

use App\Contracts\ImageGenerator;
use App\Contracts\MusicGenerator;
use App\Contracts\SoundEffectGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\VideoGenerator;
use App\Integrations\Fake\FakeImageGenerator;
use App\Integrations\Fake\FakeMusicGenerator;
use App\Integrations\Fake\FakeSoundEffectGenerator;
use App\Integrations\Fake\FakeSpeechSynthesizer;
use App\Integrations\Fake\FakeVideoGenerator;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The suite must not be able to buy anything.
 *
 * This is not hypothetical. The drivers are chosen by `.env`, and `.env` on a
 * working machine points them at fal with a funded key — so before phpunit.xml
 * pinned them, `php artisan test` POSTed to queue.fal.run from the end-to-end,
 * audio-idempotency and sound-effect tests. fal bills a submit it accepts, so
 * running the test suite spent real money.
 *
 * Two independent guards now stop that, and this test holds both in place:
 *
 * 1. phpunit.xml pins every capability to its local fake. This is the one that
 *    keeps the pipeline away from a provider at all.
 * 2. TestCase calls Http::preventStrayRequests(). This is the backstop: it does
 *    not care which driver is bound, so it also covers a test that deliberately
 *    binds a fal adapter and then forgets to fake the transport.
 *
 * Guard 1 loses to a real OS environment variable, which is why guard 2 exists.
 */
class TestSuiteCannotSpendMoneyTest extends TestCase
{
    public function test_every_capability_resolves_to_a_local_fake(): void
    {
        $this->assertInstanceOf(FakeImageGenerator::class, app(ImageGenerator::class));
        $this->assertInstanceOf(FakeVideoGenerator::class, app(VideoGenerator::class));
        $this->assertInstanceOf(FakeSpeechSynthesizer::class, app(SpeechSynthesizer::class));
        $this->assertInstanceOf(FakeMusicGenerator::class, app(MusicGenerator::class));
        $this->assertInstanceOf(FakeSoundEffectGenerator::class, app(SoundEffectGenerator::class));
    }

    public function test_every_model_defaults_to_a_free_registry_entry(): void
    {
        foreach (['video', 'image', 'speech', 'music', 'sfx'] as $capability) {
            $this->assertSame(
                'fake',
                config("studio.default_{$capability}_model"),
                "studio.default_{$capability}_model must be 'fake' under test — a priced model ".
                'makes every cost expectation depend on whatever .env happens to say.',
            );
        }
    }

    public function test_an_unfaked_outbound_request_throws_instead_of_leaving_the_machine(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://queue.fal.run/fal-ai/kling-video/requests/anything');
    }
}
