<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\MusicRequest;
use App\Contracts\MusicGenerator;
use App\Contracts\ProviderException;
use App\Services\Media\FfmpegRunner;
use RuntimeException;

/**
 * A quiet two-note drone of the requested length. Not music — but it is real
 * audio at a real duration, which is what the mix and the ducking filter need
 * in order to be verifiable (FR-13, FR-20).
 *
 * The mood shifts the root frequency so different moods are audibly different.
 */
class FakeMusicGenerator implements MusicGenerator
{
    /** @var array<string, int> mood => root frequency in Hz */
    protected const MOOD_ROOTS = [
        'tense' => 98,
        'somber' => 110,
        'neutral' => 131,
        'wondrous' => 147,
        'joyful' => 165,
        'triumphant' => 196,
    ];

    public function __construct(protected FfmpegRunner $ffmpeg) {}

    public function generate(MusicRequest $request): GeneratedMedia
    {
        $duration = max(1.0, round($request->durationSeconds, 2));
        $root = self::MOOD_ROOTS[mb_strtolower($request->mood)] ?? self::MOOD_ROOTS['neutral'];
        $fifth = (int) round($root * 1.5);

        $path = tempnam(sys_get_temp_dir(), 'studio_music_').'.wav';

        try {
            $this->ffmpeg->run([
                '-f', 'lavfi', '-i', sprintf('sine=frequency=%d:duration=%.3f:sample_rate=44100', $root, $duration),
                '-f', 'lavfi', '-i', sprintf('sine=frequency=%d:duration=%.3f:sample_rate=44100', $fifth, $duration),
                '-filter_complex', sprintf(
                    '[0:a][1:a]amix=inputs=2:duration=shortest,volume=0.18,afade=t=in:d=1,afade=t=out:st=%.3f:d=1[out]',
                    max(0.0, $duration - 1),
                ),
                '-map', '[out]',
                '-ac', '2',
                $path,
            ]);
        } catch (RuntimeException $e) {
            throw ProviderException::permanent($e->getMessage(), $this->providerName(), $e);
        }

        return new GeneratedMedia(
            path: $path,
            mime: 'audio/wav',
            model: 'fake-music-v1',
            costUsd: ($duration / 60) * $this->costPerMinuteUsd(),
            durationSeconds: $this->ffmpeg->durationSeconds($path),
            meta: ['mood' => $request->mood, 'root_hz' => $root],
        );
    }

    public function costPerMinuteUsd(): float
    {
        return 0.0;
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
