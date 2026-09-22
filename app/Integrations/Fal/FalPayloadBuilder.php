<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ProviderException;
use App\Enums\GenerationMode;
use App\Enums\ProviderFailureReason;

/**
 * Turns a ClipRequest into the JSON body one fal model accepts.
 *
 * Everything optional is opt-in per model, declared in config/studio.php under
 * `payload_parameters`. The reason is asymmetric cost: omitting a parameter a
 * model would have accepted gives you its default, which is usually fine and
 * always cheap to correct. Sending one it does not accept gives you a 422 —
 * and a 422 that reads like a wrong URL, which is the most expensive kind of
 * wrong because it sends you looking in the wrong place.
 *
 * Kling 2.5 Turbo Pro is the worked example. Its duration ladder and its lack
 * of an audio toggle are owner-verified; its resolution and aspect-ratio
 * parameter names are not. So duration is always sent, generate_audio never is,
 * and aspect_ratio only because framing is a product requirement rather than a
 * default worth accepting (a 16:9 render of a 9:16 project is unusable).
 */
class FalPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ClipRequest $request, ModelCapabilities $capabilities): array
    {
        $this->guardMode($request, $capabilities);
        $this->guardDuration($request, $capabilities);

        $payload = [
            'prompt' => $request->prompt,
            'duration' => $this->formatDuration($request->durationSeconds),
        ];

        // FR-14. On a model that can generate its own audio, the track must be
        // switched off at the provider rather than stripped afterwards — a
        // stripped track has already been paid for, and on Veo 3.1 that is
        // double the rate.
        if ($capabilities->supportsNativeAudioToggle) {
            $payload['generate_audio'] = ! $request->muteNativeAudio;
        }

        if ($capabilities->sendsParameter('aspect_ratio')) {
            $payload['aspect_ratio'] = $request->aspectRatio->value;
        }

        if ($capabilities->sendsParameter('resolution')) {
            $payload['resolution'] = $request->resolution->value;
        }

        if ($capabilities->sendsParameter('seed') && $request->seed !== null) {
            $payload['seed'] = $request->seed;
        }

        if ($request->mode !== GenerationMode::TextToVideo) {
            $payload += $this->referencePayload($request, $capabilities);
        }

        return $payload;
    }

    /**
     * fal's image inputs accept a data URI as well as a hosted URL, which is
     * what lets a locked character reference (FR-6) be sent without first
     * uploading it anywhere. That keeps the reference on our own private disk
     * until the moment it is used, and means no upload endpoint has to be
     * guessed at.
     *
     * @return array<string, mixed>
     */
    protected function referencePayload(ClipRequest $request, ModelCapabilities $capabilities): array
    {
        $references = $request->references();

        if ($references === []) {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "{$capabilities->label} was asked for {$request->mode->value} with no reference image.",
                'fal',
            );
        }

        if ($request->mode === GenerationMode::ReferenceToVideo) {
            return ['image_urls' => array_map($this->toDataUri(...), $references)];
        }

        return ['image_url' => $this->toDataUri($references[0])];
    }

    protected function toDataUri(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Reference image {$path} is missing or unreadable.",
                'fal',
            );
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Reference image {$path} could not be read.",
                'fal',
            );
        }

        return "data:{$mime};base64,".base64_encode($bytes);
    }

    protected function guardMode(ClipRequest $request, ModelCapabilities $capabilities): void
    {
        if ($capabilities->supportsMode($request->mode)) {
            return;
        }

        throw ProviderException::because(
            ProviderFailureReason::InvalidRequest,
            sprintf(
                '%s does not support %s. It supports: %s.',
                $capabilities->label,
                $request->mode->value,
                implode(', ', array_map(fn (GenerationMode $m) => $m->value, $capabilities->modes)),
            ),
            'fal',
        );
    }

    /**
     * Refuse a duration the model cannot render, before it costs anything.
     *
     * Kling renders 5 or 10 seconds and nothing between. The timing engine
     * already rounds up to a supported length (FR-16); this is the backstop
     * that makes a bug there fail loudly and for free rather than as a paid
     * provider error.
     */
    protected function guardDuration(ClipRequest $request, ModelCapabilities $capabilities): void
    {
        foreach ($capabilities->clipLengths as $length) {
            if (abs($length - $request->durationSeconds) < 0.001) {
                return;
            }
        }

        throw ProviderException::because(
            ProviderFailureReason::InvalidRequest,
            sprintf(
                '%s renders %s second clips only; %s was requested.',
                $capabilities->label,
                implode(' or ', array_map($this->trim(...), $capabilities->clipLengths)),
                $this->trim($request->durationSeconds),
            ),
            'fal',
        );
    }

    /**
     * Whole seconds go over the wire as integers.
     *
     * A model whose schema declares an integer enum can reject 5.0 where it
     * accepts 5, and every length this project renders is whole anyway.
     */
    protected function formatDuration(float $seconds): int|float
    {
        return abs($seconds - round($seconds)) < 0.001 ? (int) round($seconds) : $seconds;
    }

    protected function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
