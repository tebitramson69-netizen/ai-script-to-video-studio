<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\SoundEffectModelCapabilities;
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
            costUsd: $this->costForSeconds($request->durationSeconds),
            durationSeconds: $this->ffmpeg->durationSeconds($path),
            meta: ['description' => $request->description],
        );
    }

    /**
     * Rounded up per whole second exactly as the real provider bills, so budget
     * behaviour exercised against the fake matches what fal will charge.
     */
    public function costForSeconds(float $seconds): float
    {
        return $this->capabilities()->costForSeconds($seconds);
    }

    /**
     * As with the fake narrator: the real formula, a pretend rate.
     *
     * The hand-copied version also dropped clampDuration(), so for a scene past
     * the model's ceiling the fake and the rule it claims to imitate already
     * disagreed - in a class whose comment promises it bills exactly as the
     * provider does.
     */
    protected function capabilities(): SoundEffectModelCapabilities
    {
        return SoundEffectModelCapabilities::fromConfig('fake', [
            ...(array) config('studio.sfx_models.fake', []),
            'cost_per_effect_usd' => $this->costPerEffectUsd(),
            'cost_per_second_usd' => $this->costPerSecondUsd(),
        ]);
    }

    public function costPerEffectUsd(): float
    {
        return (float) config('studio.fake_costs.sfx_per_effect_usd', 0.0);
    }

    protected function costPerSecondUsd(): float
    {
        return (float) config('studio.fake_costs.sfx_per_second_usd', 0.0);
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
