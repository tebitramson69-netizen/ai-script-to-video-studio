<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ProviderException;
use App\Contracts\VideoGenerator;
use App\Services\Media\FfmpegRunner;
use App\Services\Provider\ModelRegistry;
use RuntimeException;

/**
 * Renders a real, playable mp4 locally with ffmpeg.
 *
 * When the shot has a locked character reference, the clip is built *from that
 * image* with a slow push-in — which is exactly the image-to-video shape the
 * real providers use (FR-8), and it makes character carry-through visible in the
 * exported video without paying a video model.
 *
 * `supportedClipLengths()` intentionally mimics a real model's discrete clip
 * lengths so the timing engine's rounding and splitting rules (FR-16/FR-17) are
 * genuinely exercised in development rather than bypassed.
 */
class FakeVideoGenerator implements VideoGenerator
{
    public function __construct(protected FfmpegRunner $ffmpeg) {}

    public function generateClip(ClipRequest $request): GeneratedMedia
    {
        $seed = $request->seed ?? random_int(1, PHP_INT_MAX);
        [$width, $height] = $request->aspectRatio->dimensions();
        $width = (int) ($width / 2);
        $height = (int) ($height / 2);

        $duration = max(1.0, round($request->durationSeconds, 2));
        $path = tempnam(sys_get_temp_dir(), 'studio_clip_').'.mp4';

        try {
            $this->ffmpeg->run($this->buildArguments($request, $path, $width, $height, $duration, $seed));
        } catch (RuntimeException $e) {
            // ffmpeg failing locally is an environment problem, not a transient
            // provider problem — retrying will fail identically.
            throw ProviderException::permanent($e->getMessage(), $this->providerName(), $e);
        }

        return new GeneratedMedia(
            path: $path,
            mime: 'video/mp4',
            model: $this->modelName(),
            costUsd: $this->estimateCostUsd($request),
            durationSeconds: $duration,
            meta: [
                'seed' => $seed,
                'prompt' => $request->prompt,
                'aspect_ratio' => $request->aspectRatio->value,
                'model_key' => $request->modelKey ?? $this->capabilities()->key,
                'mode' => $request->mode->value,
                'resolution' => $request->resolution->value,
                'used_reference_image' => $request->referenceImagePath !== null,
                'native_audio_muted' => $request->muteNativeAudio,
            ],
        );
    }

    /**
     * @return list<string>
     */
    protected function buildArguments(
        ClipRequest $request,
        string $output,
        int $width,
        int $height,
        float $duration,
        int $seed,
    ): array {
        $hasReference = $request->referenceImagePath !== null && is_file($request->referenceImagePath);

        if ($hasReference) {
            $input = ['-loop', '1', '-i', $request->referenceImagePath];

            // Scale up, then crop back down over time: a slow push-in on the
            // locked reference image. Same shape as a real i2v call.
            $filter = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,'.
                'zoompan=z=\'min(zoom+0.0006,1.10)\':d=%d:s=%dx%d:fps=25,format=yuv420p',
                (int) ($width * 1.15), (int) ($height * 1.15),
                (int) ($width * 1.15), (int) ($height * 1.15),
                max(1, (int) round($duration * 25)),
                $width, $height,
            );
        } else {
            // No character in this shot: a deterministic colour field so the
            // clip is still visually distinct from its neighbours.
            mt_srand($seed);
            $input = [
                '-f', 'lavfi',
                '-i', sprintf(
                    'color=c=0x%02X%02X%02X:s=%dx%d:d=%s:r=25',
                    mt_rand(20, 90), mt_rand(20, 90), mt_rand(40, 120),
                    $width, $height, $duration,
                ),
            ];
            $filter = 'format=yuv420p';
        }

        return [
            ...$input,
            '-t', (string) $duration,
            '-vf', $filter,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',

            // FR-14: a narrated project must not carry a second voice. The fake
            // emits no audio at all, which is the same end state as muting a
            // model that does (e.g. Veo 3.1).
            '-an',

            $output,
        ];
    }

    /**
     * Built from the registry like any real model, so the fake exercises the
     * same capability plumbing rather than a shortcut around it.
     */
    public function capabilities(): ModelCapabilities
    {
        return ModelCapabilities::fromConfig('fake', config('studio.video_models.fake', []));
    }

    public function estimateCostUsd(ClipRequest $request): float
    {
        return round(
            $request->durationSeconds * $this->capabilitiesFor($request)->costPerSecondUsd(
                $request->resolution,
                withAudio: ! $request->muteNativeAudio,
            ),
            6,
        );
    }

    /**
     * Capabilities of the model this request names, falling back to the
     * driver's own.
     *
     * The fake renders anything, but it must still price and report the model
     * it was asked for — otherwise a project pinned to an expensive model is
     * quietly costed at the fake's zero, and the budget cap protects nothing.
     */
    protected function capabilitiesFor(ClipRequest $request): ModelCapabilities
    {
        if ($request->modelKey === null) {
            return $this->capabilities();
        }

        return app(ModelRegistry::class)->video($request->modelKey);
    }

    public function supportedClipLengths(): array
    {
        return $this->capabilities()->clipLengths;
    }

    public function costPerSecondUsd(): float
    {
        return $this->capabilities()->costPerSecondUsd();
    }

    public function emitsNativeAudio(): bool
    {
        return $this->capabilities()->emitsNativeAudio;
    }

    public function modelName(): string
    {
        return 'fake';
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
