<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ImageRequest;
use App\Contracts\ImageGenerator;
use App\Contracts\ProviderException;

/**
 * Draws a real PNG with GD: a deterministic colour field derived from the seed,
 * plus the prompt text.
 *
 * It is a placeholder, but a *legible* one — when you review reference
 * candidates you can see which prompt produced which card, and the locked image
 * visibly carries through to every clip. That is enough to verify FR-6 end to end
 * without paying an image model.
 */
class FakeImageGenerator implements ImageGenerator
{
    public function generate(ImageRequest $request): GeneratedMedia
    {
        $seed = $request->seed ?? random_int(1, PHP_INT_MAX);
        [$width, $height] = $request->aspectRatio->dimensions();

        // Half resolution: placeholders do not need to be 1080p, and smaller
        // files keep local runs fast.
        $width = (int) ($width / 2);
        $height = (int) ($height / 2);

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw ProviderException::permanent('GD could not allocate an image canvas.', $this->providerName());
        }

        // Deterministic palette: the same seed always yields the same card, so
        // regenerating with a stored seed reproduces the asset (NFR-5).
        mt_srand($seed);
        $hue = mt_rand(0, 359);
        [$r, $g, $b] = $this->hsvToRgb($hue, 0.45, 0.55);
        [$r2, $g2, $b2] = $this->hsvToRgb(($hue + 40) % 360, 0.55, 0.25);

        $this->verticalGradient($image, $width, $height, [$r, $g, $b], [$r2, $g2, $b2]);

        $ink = imagecolorallocate($image, 255, 255, 255);
        $shadow = imagecolorallocate($image, 0, 0, 0);

        $label = $request->label ?? 'reference';
        $lines = array_merge(
            [strtoupper($label)],
            $this->wrap($request->prompt, 46, 6),
            ['seed '.$seed],
        );

        $y = (int) ($height / 2) - (count($lines) * 9);
        foreach ($lines as $i => $line) {
            $font = $i === 0 ? 5 : 3;
            $x = max(10, (int) (($width - imagefontwidth($font) * mb_strlen($line)) / 2));
            imagestring($image, $font, $x + 1, $y + 1, $line, $shadow);
            imagestring($image, $font, $x, $y, $line, $ink);
            $y += imagefontheight($font) + 6;
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_img_').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new GeneratedMedia(
            path: $path,
            mime: 'image/png',
            model: 'fake-image-v1',
            costUsd: $this->costPerImageUsd(),
            durationSeconds: null,
            meta: [
                'seed' => $seed,
                'prompt' => $request->prompt,
                'aspect_ratio' => $request->aspectRatio->value,
            ],
        );
    }

    public function costPerImageUsd(): float
    {
        return 0.0;
    }

    public function providerName(): string
    {
        return 'fake';
    }

    /**
     * @param  array{0:int,1:int,2:int}  $top
     * @param  array{0:int,1:int,2:int}  $bottom
     * @param  \GdImage  $image
     */
    protected function verticalGradient($image, int $width, int $height, array $top, array $bottom): void
    {
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $colour = imagecolorallocate(
                $image,
                (int) ($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) ($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) ($top[2] + ($bottom[2] - $top[2]) * $t),
            );
            imageline($image, 0, $y, $width, $y, $colour);
        }
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    protected function hsvToRgb(float $h, float $s, float $v): array
    {
        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return [(int) (($r + $m) * 255), (int) (($g + $m) * 255), (int) (($b + $m) * 255)];
    }

    /**
     * @return list<string>
     */
    protected function wrap(string $text, int $width, int $maxLines): array
    {
        $wrapped = explode("\n", wordwrap(trim($text), $width, "\n", true));

        return array_slice(array_filter($wrapped), 0, $maxLines);
    }
}
