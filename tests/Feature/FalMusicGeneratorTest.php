<?php

namespace Tests\Feature;

use App\Contracts\Data\MusicRequest;
use App\Contracts\MusicGenerator;
use App\Contracts\ProviderException;
use App\Contracts\SpeechSynthesizer;
use App\Contracts\VideoGenerator;
use App\Enums\ProviderFailureReason;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalMusicGenerator;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The background music bed on a fal-hosted music model (FR-13).
 *
 * fal is unreachable from this codebase, so every assertion here is against
 * faked HTTP: these tests prove the adapter's own decisions, not the provider's
 * schema. Three of those decisions matter more than the rest.
 *
 * 1. A model that can sing is refused unless something switches singing off. A
 *    sung line over the narration is a ruined video, not a degraded one.
 * 2. Cost is charged on the length REQUESTED, and flat-priced models are costed
 *    correctly regardless of length.
 * 3. A timeline longer than the model can render is clamped and flagged, not
 *    refused — the assembler loops the bed.
 */
class FalMusicGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_music_model', 'stable-audio-3');
    }

    protected function generator(): FalMusicGenerator
    {
        return new FalMusicGenerator(
            FalClient::fromConfig(),
            app(ModelRegistry::class),
            app(FfmpegRunner::class),
        );
    }

    /**
     * A real, measurable audio file, so the duration assertions mean something.
     */
    protected function fakeFalReturning(float $seconds = 4.0, ?array $result = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bed_').'.wav';

        app(FfmpegRunner::class)->run([
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=220:duration=%.3f:sample_rate=44100', $seconds),
            '-ac', '2', '-y', $path,
        ]);

        Http::fake([
            'queue.fal.run/*/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => Http::response($result ?? [
                'audio_file' => ['url' => 'https://cdn.fal.media/bed.wav'],
                'seed' => 4242,
            ]),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-music-1']),
            'cdn.fal.media/*' => Http::response(file_get_contents($path)),
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

    public function test_it_reads_stable_audios_own_output_field(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning(4.0);

        // 'audio_file' rather than the 'audio' every other audio model on fal
        // uses. Picking the wrong key is a silent failure: the adapter would
        // report no audio URL on a call that succeeded and was billed.
        $media = $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 4.0));

        $this->assertSame('audio/wav', $media->mime);
        $this->assertSame('stable-audio-3', $media->model);
        $this->assertEqualsWithDelta(4.0, $media->durationSeconds, 0.3);
    }

    public function test_the_duration_is_measured_from_the_file(): void
    {
        $this->skipWithoutFfmpeg();

        // Asked for 30 seconds, given 4. The measured figure has to win:
        // GenerateMusicJob decides whether an existing track still covers the
        // timeline by comparing this number against the timeline, and a
        // requested length recorded as a real one would make that check answer
        // a question it was never asked.
        $this->fakeFalReturning(4.0);

        $media = $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 30.0));

        $this->assertEqualsWithDelta(4.0, $media->durationSeconds, 0.3);
        $this->assertSame(30.0, $media->meta['requested_duration_seconds']);
    }

    public function test_the_mood_becomes_an_instrumentation_brief_not_a_bare_adjective(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        $this->generator()->generate(new MusicRequest(mood: 'tense', durationSeconds: 10.0));

        $prompt = $this->sentPayload()['prompt'] ?? '';

        $this->assertNotSame('tense', $prompt);
        $this->assertStringContainsString('strings', $prompt);
        $this->assertStringContainsString('no vocals', $prompt);
        $this->assertStringContainsString('voiceover', $prompt);
    }

    public function test_an_unknown_mood_falls_back_to_neutral_rather_than_sending_it_raw(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        // Scene moods come from a closed enum, so an unknown one means a
        // migration or a hand-edited row. A bed that is merely generic beats a
        // 422, and beats sending a word the model will read as a genre.
        $this->generator()->generate(new MusicRequest(mood: 'sarcastic', durationSeconds: 10.0));

        $this->assertStringContainsString('calm instrumental', $this->sentPayload()['prompt']);
    }

    public function test_a_flat_priced_model_costs_the_same_whatever_the_length(): void
    {
        // Stable Audio 3 bills per request, not per second. A per-minute rate
        // cannot express that, which is why the contract takes a duration.
        $music = $this->generator();

        $this->assertSame(0.05, $music->costForSeconds(10.0));
        $this->assertSame(0.05, $music->costForSeconds(300.0));
    }

    public function test_a_per_second_model_is_costed_by_the_second(): void
    {
        config()->set('studio.default_music_model', 'ace-step');

        // 64 seconds at $0.0002 — the figure that has to survive rounding.
        // Rounded to two places it would be $0.01 at any length, and at zero
        // the budget cap would stop counting music altogether.
        $this->assertSame(0.0128, $this->generator()->costForSeconds(64.0));
    }

    public function test_it_charges_for_the_length_it_asked_for_not_the_length_it_got(): void
    {
        $this->skipWithoutFfmpeg();
        config()->set('studio.default_music_model', 'ace-step');
        $this->fakeFalReturning(4.0);

        // The provider returned 4 seconds of a 60-second request. Costing the
        // measured length would record the run as cheaper than it was billed.
        $media = $this->generator()->generate(new MusicRequest(mood: 'joyful', durationSeconds: 60.0));

        $this->assertSame(0.012, $media->costUsd);
    }

    public function test_a_timeline_longer_than_the_model_is_clamped_and_flagged(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        // 500 seconds of video against a 380-second ceiling. Refusing would be
        // wrong — the assembler loops the music input, so the bed repeats
        // rather than leaving silence — but an audible repeat needs a traceable
        // cause.
        $media = $this->generator()->generate(new MusicRequest(mood: 'wondrous', durationSeconds: 500.0));

        $this->assertSame(380.0, $this->sentPayload()['duration']);
        $this->assertTrue($media->meta['looped_to_cover_timeline']);
    }

    public function test_a_timeline_the_model_covers_is_not_flagged_as_looped(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        $media = $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 64.0));

        $this->assertSame(64.0, $this->sentPayload()['duration']);
        $this->assertFalse($media->meta['looped_to_cover_timeline']);
    }

    public function test_a_singing_model_is_sent_the_instrumental_flag(): void
    {
        $this->skipWithoutFfmpeg();
        config()->set('studio.default_music_model', 'ace-step');
        $this->fakeFalReturning();

        $this->generator()->generate(new MusicRequest(mood: 'somber', durationSeconds: 20.0));

        $this->assertTrue($this->sentPayload()['instrumental']);
    }

    public function test_a_singing_model_with_nothing_switching_singing_off_is_refused_before_spending(): void
    {
        Http::fake();

        config()->set('studio.music_models.karaoke', [
            'label' => 'Sings everything (fal)',
            'endpoint' => 'fal-ai/imaginary/song',
            'generates_vocals' => true,
            'payload_defaults' => [],
        ]);
        config()->set('studio.default_music_model', 'karaoke');

        try {
            $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 20.0));
            $this->fail('A model that can sing was called with nothing stopping it.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertStringContainsString('bed under narration', $e->getMessage());
        }

        // The point of refusing here rather than at the mix: nothing was paid for.
        Http::assertNothingSent();
    }

    public function test_a_payload_default_cannot_overwrite_the_length_that_was_asked_for(): void
    {
        $this->skipWithoutFfmpeg();

        config()->set('studio.music_models.sloppy', [
            'label' => 'Sloppy registry entry (fal)',
            'endpoint' => 'fal-ai/imaginary/bed',
            'max_duration_seconds' => 120.0,
            'payload_defaults' => ['duration' => 8, 'prompt' => 'elevator jazz'],
        ]);
        config()->set('studio.default_music_model', 'sloppy');
        $this->fakeFalReturning();

        $this->generator()->generate(new MusicRequest(mood: 'tense', durationSeconds: 45.0));

        $payload = $this->sentPayload();

        $this->assertSame(45.0, $payload['duration']);
        $this->assertStringContainsString('tense instrumental', $payload['prompt']);
    }

    public function test_a_model_that_takes_no_length_is_not_sent_an_invented_field(): void
    {
        $this->skipWithoutFfmpeg();

        // One unaccepted field fails the whole request, and fal's 422 reads
        // like a wrong URL — the most expensive kind of wrong.
        config()->set('studio.music_models.lengthless', [
            'label' => 'Fixed-length model (fal)',
            'endpoint' => 'fal-ai/imaginary/fixed',
            'duration_parameter' => null,
        ]);
        config()->set('studio.default_music_model', 'lengthless');
        $this->fakeFalReturning();

        $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 45.0));

        $this->assertArrayNotHasKey('duration', $this->sentPayload());
    }

    public function test_a_model_with_no_endpoint_is_refused_rather_than_called(): void
    {
        Http::fake();
        config()->set('studio.default_music_model', 'fake');

        try {
            $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 20.0));
            $this->fail('A model with no endpoint was called anyway.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertStringContainsString('declares no fal endpoint', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_submit_with_no_request_id_is_permanent_because_the_work_may_be_billing(): void
    {
        Http::fake(['queue.fal.run/*' => Http::response(['queue_position' => 3])]);

        try {
            $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 20.0));
            $this->fail('A submit with no request id was treated as recoverable.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
        }
    }

    public function test_a_result_with_no_audio_url_names_the_keys_it_did_get(): void
    {
        Http::fake([
            'queue.fal.run/*/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => Http::response(['detail' => 'nothing useful']),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-music-2']),
        ]);

        try {
            $this->generator()->generate(new MusicRequest(mood: 'neutral', durationSeconds: 20.0));
            $this->fail('A result with no audio URL passed silently.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('carried no audio URL', $e->getMessage());
            $this->assertStringContainsString('detail', $e->getMessage());
        }
    }

    public function test_the_seed_fal_used_is_recorded_so_a_liked_bed_can_be_regenerated(): void
    {
        $this->skipWithoutFfmpeg();
        $this->fakeFalReturning();

        $media = $this->generator()->generate(new MusicRequest(mood: 'triumphant', durationSeconds: 20.0));

        $this->assertSame(4242, $media->meta['seed']);
    }

    /**
     * The bug this file caught on the way in: FalClient takes its credential as
     * a plain string, so nothing in the container could build it and every fal
     * driver threw on resolution. Asserted for all three so it cannot come back
     * for one of them.
     */
    public function test_every_fal_driver_resolves_through_the_container(): void
    {
        config()->set('studio.video_generator', 'fal');
        config()->set('studio.speech_synthesizer', 'fal');
        config()->set('studio.music_generator', 'fal');

        $this->assertInstanceOf(FalMusicGenerator::class, app(MusicGenerator::class));
        $this->assertSame('fal', app(VideoGenerator::class)->providerName());
        $this->assertSame('fal', app(SpeechSynthesizer::class)->providerName());
    }
}
