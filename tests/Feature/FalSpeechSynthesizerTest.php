<?php

namespace Tests\Feature;

use App\Contracts\Data\SpeechRequest;
use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Integrations\Fal\FalClient;
use App\Integrations\Fal\FalSpeechSynthesizer;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Narration on a fal-hosted TTS model.
 *
 * Narration is the master clock (FR-16/17/18), which makes one assertion here
 * more important than the rest: the duration is MEASURED from the downloaded
 * file, never taken from what the provider says. A reported figure a tenth of a
 * second out desynchronises every shot after it, and the error accumulates down
 * the timeline rather than staying local.
 */
class FalSpeechSynthesizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('studio.fal.key', 'test-key');
        config()->set('studio.fal.queue_url', 'https://queue.fal.run');
        config()->set('studio.default_speech_model', 'kokoro');
    }

    protected function synthesizer(): FalSpeechSynthesizer
    {
        return new FalSpeechSynthesizer(
            FalClient::fromConfig(),
            app(ModelRegistry::class),
            app(FfmpegRunner::class),
        );
    }

    /**
     * A real, measurable audio file, so duration assertions mean something.
     */
    protected function fakeFalReturning(float $seconds = 3.0, string $extension = 'mp3'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'src_').'.'.$extension;

        app(FfmpegRunner::class)->run([
            '-f', 'lavfi',
            '-i', sprintf('sine=frequency=440:duration=%.3f:sample_rate=44100', $seconds),
            '-ac', '1', '-y', $path,
        ]);

        Http::fake([
            'queue.fal.run/*/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => Http::response([
                'audio' => ['url' => "https://cdn.fal.media/voice.{$extension}"],
            ]),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-vo-1']),
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

    public function test_the_duration_is_measured_from_the_file_not_taken_from_the_provider(): void
    {
        $this->skipWithoutFfmpeg();

        $source = $this->fakeFalReturning(seconds: 3.0);

        // fal is not asked for a duration and would not be believed if it gave
        // one. This is the master clock; it is measured.
        $media = $this->synthesizer()->synthesize(new SpeechRequest('The river was calm that morning.'));

        $this->assertEqualsWithDelta(3.0, $media->durationSeconds, 0.25);
        $this->assertFileExists($media->path);

        @unlink($source);
        @unlink($media->path);
    }

    public function test_the_language_chooses_the_endpoint(): void
    {
        $this->skipWithoutFfmpeg();

        $source = $this->fakeFalReturning();

        // Kokoro ships one model id per language, so language selection IS
        // endpoint selection. Cameroon is officially bilingual, which is why
        // this is a first-class case rather than Phase 3 work.
        $this->synthesizer()->synthesize(new SpeechRequest('La rivière était calme.', language: 'fr'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fal-ai/kokoro/french'));

        @unlink($source);
    }

    public function test_a_regional_tag_falls_back_to_its_base_language(): void
    {
        $this->skipWithoutFfmpeg();

        $source = $this->fakeFalReturning();

        $this->synthesizer()->synthesize(new SpeechRequest('La rivière était calme.', language: 'fr-CA'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fal-ai/kokoro/french'));

        @unlink($source);
    }

    public function test_a_language_the_model_cannot_speak_is_refused_before_spending(): void
    {
        Http::fake(['*' => Http::response(['request_id' => 'req-1'])]);

        // Narration in the wrong language is a wrong result, not a degraded
        // one — and it would be paid for before anyone noticed. So this never
        // silently falls back to English.
        try {
            $this->synthesizer()->synthesize(new SpeechRequest('Habari ya asubuhi.', language: 'sw'));
            $this->fail('Expected an unsupported language to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('en, fr', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_empty_narration_is_refused_rather_than_billed(): void
    {
        Http::fake(['*' => Http::response(['request_id' => 'req-1'])]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/empty narration/');

        $this->synthesizer()->synthesize(new SpeechRequest('   '));
    }

    public function test_only_the_text_goes_on_the_wire_by_default(): void
    {
        $this->skipWithoutFfmpeg();

        $source = $this->fakeFalReturning();

        $this->synthesizer()->synthesize(new SpeechRequest('The river was calm.', voiceId: 'some-voice'));

        // The voice parameter's name and accepted values are unconfirmed on
        // these models, and one unaccepted parameter fails the whole call.
        // Without it the model uses its own default voice — a usable narration
        // rather than an error.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'kokoro')) {
                return true;
            }

            return $r->data() === ['text' => 'The river was calm.'];
        });

        @unlink($source);
    }

    public function test_cost_is_charged_per_thousand_characters_of_the_model_in_use(): void
    {
        $this->skipWithoutFfmpeg();

        $source = $this->fakeFalReturning();
        $text = str_repeat('a', 2000);

        $media = $this->synthesizer()->synthesize(new SpeechRequest($text));

        // 2000 characters at Kokoro's $0.02/1k.
        $this->assertEqualsWithDelta(0.04, $media->costUsd, 0.0001);

        @unlink($source);
        @unlink($media->path);
    }

    public function test_switching_model_is_a_config_line(): void
    {
        config()->set('studio.default_speech_model', 'elevenlabs-v3');

        $synthesizer = $this->synthesizer();

        $this->assertSame(0.10, $synthesizer->costPer1kCharactersUsd());

        // ElevenLabs takes one endpoint for every language, unlike Kokoro.
        $this->assertSame(
            'fal-ai/elevenlabs/tts/eleven-v3',
            $synthesizer->capabilities()->endpointFor('fr'),
        );
    }

    public function test_a_result_with_no_audio_url_fails_rather_than_storing_nothing(): void
    {
        Http::fake([
            'queue.fal.run/*/status' => Http::response(['status' => 'COMPLETED']),
            'queue.fal.run/*/requests/*' => Http::response(['status' => 'COMPLETED', 'logs' => []]),
            'queue.fal.run/*' => Http::response(['request_id' => 'req-vo-1']),
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no audio URL/');

        $this->synthesizer()->synthesize(new SpeechRequest('The river was calm.'));
    }

    public function test_a_submission_with_no_request_id_is_not_retried(): void
    {
        // fal has accepted and is billing for it. Retrying pays twice for
        // narration that is already being generated.
        Http::fake(['queue.fal.run/*' => Http::response(['queue_position' => 2])]);

        try {
            $this->synthesizer()->synthesize(new SpeechRequest('The river was calm.'));
            $this->fail('Expected a submission with no request id to fail.');
        } catch (ProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('billable', $e->getMessage());
        }
    }
}
