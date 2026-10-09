<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SpeechModelCapabilities;
use App\Contracts\Data\SpeechRequest;
use App\Contracts\ProviderException;
use App\Contracts\SpeechSynthesizer;
use App\Services\Media\FfmpegRunner;
use App\Services\Timing\NarrationEstimator;
use RuntimeException;

/**
 * Produces a real WAV of the *correct length* for the given text.
 *
 * Length is the thing that matters. Narration is the master clock (FR-16), so a
 * narration track of the right duration exercises every timing, trimming and
 * ducking rule in assembly. It is a soft tone rather than silence so you can
 * actually hear the music duck underneath it (FR-20) when you play the export.
 */
class FakeSpeechSynthesizer implements SpeechSynthesizer
{
    public function __construct(
        protected FfmpegRunner $ffmpeg,
        protected NarrationEstimator $estimator,
    ) {}

    public function synthesize(SpeechRequest $request): GeneratedMedia
    {
        $duration = max(0.5, $this->estimator->estimateSeconds($request->text));
        $path = tempnam(sys_get_temp_dir(), 'studio_vo_').'.wav';

        try {
            $this->ffmpeg->run([
                '-f', 'lavfi',
                '-i', sprintf('sine=frequency=220:duration=%.3f:sample_rate=44100', $duration),

                // Quiet, and gently amplitude-modulated so it reads as "speech
                // is happening here" rather than as a test tone.
                '-af', 'tremolo=f=5:d=0.7,volume=0.25',

                '-ac', '1',
                $path,
            ]);
        } catch (RuntimeException $e) {
            throw ProviderException::permanent($e->getMessage(), $this->providerName(), $e);
        }

        return new GeneratedMedia(
            path: $path,
            mime: 'audio/wav',
            model: 'fake-tts-v1',
            costUsd: $this->costForCharacters(mb_strlen($request->text)),

            // Probe rather than trust the requested duration: the real drivers
            // must report what the provider actually returned, and the pipeline
            // should be written against that behaviour from day one.
            durationSeconds: $this->ffmpeg->durationSeconds($path),

            meta: [
                'characters' => mb_strlen($request->text),
                'language' => $request->language,
                'voice_id' => $request->voiceId,
            ],
        );
    }

    /**
     * Rounded up per request exactly as the real provider bills, so budget
     * behaviour exercised against the fake matches what fal will charge. A fake
     * that prices work differently from the thing it stands in for teaches the
     * cap the wrong lesson.
     */
    public function costForCharacters(int $characters): float
    {
        return $this->capabilities()->costForCharacters($characters);
    }

    /**
     * A capabilities DTO carrying the fake's own rate, so the billing FORMULA
     * is the real one and only the number is pretend - the pattern
     * FakeVideoGenerator already uses.
     *
     * It used to hand-copy the ceil-to-a-whole-unit expression, importing the
     * DTO purely to borrow its constant. The rounding rule is the part of this
     * still marked unverified above 1,000 characters, so it is exactly the part
     * most likely to be revised - and a copy here would leave the fake, and
     * the test that asserts the two agree, pinning the old rule.
     */
    protected function capabilities(): SpeechModelCapabilities
    {
        return new SpeechModelCapabilities(
            key: 'fake',
            label: 'Fake narrator (local tone, free)',
            endpoint: null,
            endpoints: [],
            costPer1kCharactersUsd: $this->costPer1kCharactersUsd(),
        );
    }

    public function costPer1kCharactersUsd(): float
    {
        return (float) config('studio.fake_costs.tts_per_1k_chars_usd', 0.0);
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
