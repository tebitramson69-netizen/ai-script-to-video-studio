<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ImageModelCapabilities;
use App\Contracts\Data\ImageRequest;
use App\Contracts\ImageGenerator;
use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Services\Provider\ModelRegistry;
use InvalidArgumentException;

/**
 * Character reference candidates on a fal-hosted image model (FR-5).
 *
 * Small, cheap work with two requirements that are not negotiable, and neither
 * is about picture quality.
 *
 * SEED. The reference the owner locks has to be reproducible (PRD §10.2,
 * NFR-5). A model that ignores the seed produces a reference that can never be
 * regenerated, which quietly turns "locked" into "lucky".
 *
 * SHAPE. The locked reference IS the framing for every image-to-video shot
 * built from it — Kling's image-to-video endpoint takes no aspect_ratio of its
 * own and inherits the starting frame's. So the project's ratio is translated
 * into the model's size preset and a ratio the model cannot produce is refused
 * here, for free, rather than mis-framing every character shot in the video at
 * full price.
 */
class FalImageGenerator implements ImageGenerator
{
    protected const TIMEOUT_SECONDS = 180;

    protected const POLL_SECONDS = 2;

    public function __construct(
        protected FalClient $client,
        protected ModelRegistry $registry,
    ) {}

    public function generate(ImageRequest $request): GeneratedMedia
    {
        $model = $this->capabilities();
        $endpoint = $this->endpointFor($model);
        $payload = $this->buildPayload($request, $model);

        $mapper = $this->client->mapper();
        $submit = $this->client->submit($endpoint, $payload);
        $requestId = $mapper->requestId($submit);

        if ($requestId === null) {
            throw ProviderException::permanent(
                'fal accepted the image request but returned no request id, so it cannot be collected. '.
                'The work may still be running and billable.',
                'fal',
            );
        }

        $body = $this->awaitResult($endpoint, $requestId, $submit);
        $url = $mapper->imageUrl($body);

        if ($url === null) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported the image was finished but the result carried no image URL. '.
                'Keys were: '.implode(', ', array_keys($mapper->flatten($body))),
                'fal',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_ref_').$this->extensionFor($url);
        $this->client->download($url, $path);

        return new GeneratedMedia(
            path: $path,
            mime: $this->mimeFor($path),
            model: $model->key,
            costUsd: $model->costPerImageUsd,

            // Null, not zero: a still has no duration, and the distinction
            // matters to the assembler.
            durationSeconds: null,

            meta: [
                'provider_request_id' => $requestId,
                'source_url' => $url,
                'endpoint' => $endpoint,
                'prompt' => $request->prompt,
                'label' => $request->label,
                'aspect_ratio' => $request->aspectRatio->value,
                'image_size' => $payload['image_size'] ?? null,

                // Recorded so a locked reference can be regenerated exactly.
                // fal echoes the seed it used when none was supplied, so prefer
                // its answer over ours (NFR-5).
                'seed' => $this->seedFrom($body) ?? $request->seed,

                'actual_cost_usd' => $mapper->actualCostUsd($body),
            ],
        );
    }

    public function costPerImageUsd(): float
    {
        return $this->capabilities()->costPerImageUsd;
    }

    public function providerName(): string
    {
        return 'fal';
    }

    public function capabilities(): ImageModelCapabilities
    {
        return $this->registry->defaultImage();
    }

    /**
     * @param  array<string, mixed>  $submit
     * @return array<string, mixed>
     */
    protected function awaitResult(string $endpoint, string $requestId, array $submit): array
    {
        $mapper = $this->client->mapper();
        $statusUrl = $mapper->statusUrl($submit);
        $resultUrl = $mapper->resultUrl($submit);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $status = $mapper->status($this->client->status($endpoint, $requestId, $statusUrl));

            if ($status === ProviderRequestStatus::Completed) {
                return $this->client->result($endpoint, $requestId, $resultUrl);
            }

            if ($status === ProviderRequestStatus::Failed || $status === ProviderRequestStatus::Cancelled) {
                throw ProviderException::because(
                    ProviderFailureReason::ProviderError,
                    "fal reported the image request {$requestId} did not complete.",
                    'fal',
                );
            }

            sleep(self::POLL_SECONDS);
        }

        throw ProviderException::because(
            ProviderFailureReason::Timeout,
            sprintf(
                'fal image request %s did not finish within %ds. It may still be running and billable.',
                $requestId,
                self::TIMEOUT_SECONDS,
            ),
            'fal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(ImageRequest $request, ImageModelCapabilities $model): array
    {
        $payload = ['prompt' => $request->prompt];

        if ($model->sendsParameter('image_size')) {
            try {
                $payload['image_size'] = $model->imageSizeFor($request->aspectRatio);
            } catch (InvalidArgumentException $e) {
                // Free to refuse here; expensive to discover later. A reference
                // in the wrong shape is inherited by every image-to-video shot
                // built from it.
                throw ProviderException::because(
                    ProviderFailureReason::InvalidRequest,
                    $e->getMessage(),
                    'fal',
                    $e,
                );
            }
        }

        if ($request->seed !== null && $model->sendsParameter('seed')) {
            $payload['seed'] = $request->seed;
        }

        return $payload;
    }

    protected function endpointFor(ImageModelCapabilities $model): string
    {
        $endpoint = trim((string) $model->endpoint);

        if ($endpoint === '') {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Image model '{$model->key}' declares no fal endpoint. ".
                'Add one to config/studio.php image_models, or bind a different driver.',
                'fal',
            );
        }

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function seedFrom(array $body): ?int
    {
        $seed = $body['seed'] ?? null;

        return is_int($seed) || (is_string($seed) && ctype_digit($seed)) ? (int) $seed : null;
    }

    protected function extensionFor(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'avif'], true)
            ? ".{$extension}"
            : '.png';
    }

    protected function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/png',
        };
    }
}
