<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SoundEffectRequest;
use App\Contracts\ProviderException;
use App\Contracts\SoundEffectGenerator;
use App\Services\Media\FfmpegRunner;
use RuntimeException;

/**
 * Phase 2 capability (PRD §7). Implemented now only so the contract is real and
 * the assembly timeline already has a place for SFX.
 */
class FakeSoundEffectGenerator implements SoundEffectGenerator
{
    public function __construct(protected FfmpegRunner $ffmpeg) {}

    public function generate(SoundEffectRequest $request): GeneratedMedia
    {
        $duration = max(0.2, round($request->durationSeconds, 2));
        $path = tempnam(sys_get_temp_dir(), 'studio_sfx_').'.wav';

        try {
            $this->ffmpeg->run([
                '-f', 'lavfi',
                '-i', sprintf('anoisesrc=duration=%.3f:color=brown:sample_rate=44100', $duration),
                '-af', 'volume=0.3,afade=t=out:st=0:d='.$duration,
                '-ac', '1',
                $path,
            ]);
        } catch (RuntimeException $e) {
            throw ProviderException::permanent($e->getMessage(), $this->providerName(), $e);
        }

        return new GeneratedMedia(
            path: $path,
            mime: 'audio/wav',
            model: 'fake-sfx-v1',
            costUsd: $this->costPerEffectUsd(),
            durationSeconds: $this->ffmpeg->durationSeconds($path),
            meta: ['description' => $request->description],
        );
    }

    public function costPerEffectUsd(): float
    {
        return 0.0;
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
