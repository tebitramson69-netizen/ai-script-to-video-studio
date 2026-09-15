<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
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
            costUsd: (mb_strlen($request->text) / 1000) * $this->costPer1kCharactersUsd(),

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

    public function costPer1kCharactersUsd(): float
    {
        return 0.0;
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
