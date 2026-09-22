<?php

namespace App\Integrations\Fal;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;
use App\Contracts\Data\ModelCapabilities;
use App\Contracts\ProviderException;
use App\Contracts\QueueableVideoGenerator;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Services\Provider\ModelRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Renders a shot on a fal-hosted video model (FR-9).
 *
 * Queue-based by nature: a real render takes minutes, so submitClip() returns
 * as soon as fal accepts the work and the result is collected later by the
 * poller. generateClip() exists only to satisfy VideoGenerator for callers that
 * genuinely want to block, and is built from the same three steps.
 *
 * The model is chosen per request, not per driver. One fal credential fronts
 * every model they host, so a project pinned to Kling and a project pinned to
 * Veo run through this same class with different endpoints — which is why
 * ClipRequest carries a modelKey.
 *
 * What this class does NOT do is decide whether the work should happen: the
 * budget cap, the idempotency claim and the ledger row are all settled before
 * submitClip() is reached. By the time it runs, the right to spend this money
 * has already been granted.
 */
class FalVideoGenerator implements QueueableVideoGenerator
{
    /**
     * How long a blocking generateClip() will wait before giving up.
     *
     * Bounded because an unbounded wait in a queue worker is how a job holds a
     * worker hostage for an hour. Giving up here does not cancel the work at
     * fal — the request id is already in the ledger, and the poller collects it.
     */
    protected const SYNC_TIMEOUT_SECONDS = 600;

    protected const SYNC_POLL_SECONDS = 5;

    public function __construct(
        protected FalClient $client,
        protected ModelRegistry $registry,
        protected FalPayloadBuilder $payloads = new FalPayloadBuilder,
    ) {}

    public function submitClip(ClipRequest $request): string
    {
        $capabilities = $this->capabilitiesFor($request);
        $endpoint = $this->endpointFor($capabilities);
        $payload = $this->payloads->build($request, $capabilities);

        $body = $this->client->submit($endpoint, $payload);
        $mapper = $this->client->mapper();
        $requestId = $mapper->requestId($body);

        if ($requestId === null) {
            // fal has accepted the work and is billing for it, but we cannot
            // name it — so we can neither poll it nor cancel it.
            //
            // permanent() rather than because(ProviderError): the taxonomy ties
            // retryability to the reason so the two can never disagree, and
            // ProviderError is retryable. It is the right description and the
            // wrong behaviour. Retrying here would submit a second paid render
            // of work that is already running, which is exactly the
            // double-billing the whole idempotency layer exists to prevent.
            throw ProviderException::permanent(
                'fal accepted the submission but returned no request id. Keys were: '.
                implode(', ', array_keys($body)).'. The work may still be running and billable, '.
                'so this is not retried — check the fal dashboard before resubmitting.',
                'fal',
            );
        }

        // fal's own status_url / response_url are authoritative about its
        // routing, so keep them for as long as this request could plausibly
        // still be running. If the cache is cold when the poller comes back —
        // a restarted worker, a flushed cache — FalClient reconstructs them,
        // which is why this is an optimisation and not the mechanism.
        $this->rememberRouting($requestId, [
            'endpoint' => $endpoint,
            'model_key' => $capabilities->key,
            'status_url' => $mapper->statusUrl($body),
            'result_url' => $mapper->resultUrl($body),
            'duration' => $request->durationSeconds,
            'estimate' => $this->estimateCostUsd($request),
            'meta' => $this->metaFor($request, $capabilities),
        ]);

        return $requestId;
    }

    public function checkStatus(string $providerRequestId): ProviderRequestStatus
    {
        $routing = $this->routingFor($providerRequestId);

        $body = $this->client->status(
            $routing['endpoint'],
            $providerRequestId,
            $routing['status_url'] ?? null,
        );

        $mapper = $this->client->mapper();
        $status = $mapper->status($body);

        if ($status === null) {
            throw ProviderException::because(
                ProviderFailureReason::Unknown,
                sprintf(
                    'fal reported an unrecognised status for %s: %s',
                    $providerRequestId,
                    $mapper->rawStatus($body) ?? json_encode($body),
                ),
                'fal',
            );
        }

        if ($status === ProviderRequestStatus::Failed) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported the generation failed: '.
                ($mapper->errorMessage($body) ?? 'no reason given'),
                'fal',
            );
        }

        return $status;
    }

    public function fetchResult(string $providerRequestId): GeneratedMedia
    {
        $routing = $this->routingFor($providerRequestId);

        $body = $this->client->result(
            $routing['endpoint'],
            $providerRequestId,
            $routing['result_url'] ?? null,
        );

        $mapper = $this->client->mapper();
        $url = $mapper->videoUrl($body);

        if ($url === null) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'fal reported completion but the result carried no video URL. Keys were: '.
                implode(', ', array_keys($mapper->flatten($body))),
                'fal',
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'studio_fal_').'.mp4';
        $this->client->download($url, $path);

        $this->forgetRouting($providerRequestId);

        return new GeneratedMedia(
            path: $path,
            mime: 'video/mp4',
            model: $routing['model_key'] ?? $this->modelName(),
            costUsd: (float) ($routing['estimate'] ?? 0.0),
            durationSeconds: $mapper->durationSeconds($body) ?? ($routing['duration'] ?? null),
            meta: [
                ...($routing['meta'] ?? []),
                'provider_request_id' => $providerRequestId,
                'source_url' => $url,

                // Null when fal does not report a charge, and deliberately not
                // coerced to zero: the completer keeps the estimate rather than
                // recording a spend of nothing (FR-11).
                'actual_cost_usd' => $mapper->actualCostUsd($body),
            ],
        );
    }

    /**
     * Submit, wait, collect.
     *
     * Only for callers that genuinely want to block. The pipeline takes the
     * async path, because a worker held open for the length of a render is a
     * worker that loses the thread if it restarts — while fal carries on
     * generating, and charging.
     */
    public function generateClip(ClipRequest $request): GeneratedMedia
    {
        $requestId = $this->submitClip($request);
        $deadline = microtime(true) + self::SYNC_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->checkStatus($requestId) === ProviderRequestStatus::Completed) {
                return $this->fetchResult($requestId);
            }

            sleep(self::SYNC_POLL_SECONDS);
        }

        throw ProviderException::because(
            ProviderFailureReason::Timeout,
            sprintf(
                'fal request %s did not finish within %ds. It is probably still running and billable — '.
                'collect it with the poller rather than resubmitting.',
                $requestId,
                self::SYNC_TIMEOUT_SECONDS,
            ),
            'fal',
        );
    }

    public function capabilities(): ModelCapabilities
    {
        return $this->registry->defaultVideo();
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
        return $this->capabilities()->key;
    }

    public function providerName(): string
    {
        return 'fal';
    }

    protected function capabilitiesFor(ClipRequest $request): ModelCapabilities
    {
        return $request->modelKey === null
            ? $this->capabilities()
            : $this->registry->video($request->modelKey);
    }

    protected function endpointFor(ModelCapabilities $capabilities): string
    {
        $endpoint = trim((string) $capabilities->endpoint);

        if ($endpoint === '') {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Model '{$capabilities->key}' declares no fal endpoint. ".
                'Add one to config/studio.php video_models, or bind a different driver.',
                'fal',
            );
        }

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $routing
     */
    protected function rememberRouting(string $requestId, array $routing): void
    {
        Cache::put($this->cacheKey($requestId), $routing, now()->addDay());
    }

    protected function forgetRouting(string $requestId): void
    {
        Cache::forget($this->cacheKey($requestId));
    }

    /**
     * What we know about an in-flight request.
     *
     * A cold cache is expected, not exceptional — that is the whole point of
     * the request id outliving the process. The endpoint falls back to the
     * default model's, which is right whenever one model is in use and
     * recoverable by setting STUDIO_DEFAULT_VIDEO_MODEL when it is not.
     *
     * @return array<string, mixed>
     */
    protected function routingFor(string $requestId): array
    {
        $routing = Cache::get($this->cacheKey($requestId));

        if (is_array($routing) && ($routing['endpoint'] ?? '') !== '') {
            return $routing;
        }

        return [
            'endpoint' => $this->endpointFor($this->capabilities()),
            'model_key' => $this->capabilities()->key,
        ];
    }

    protected function cacheKey(string $requestId): string
    {
        return "fal:request:{$requestId}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function metaFor(ClipRequest $request, ModelCapabilities $capabilities): array
    {
        return [
            'prompt' => $request->prompt,
            'model_key' => $capabilities->key,
            'endpoint' => $capabilities->endpoint,
            'mode' => $request->mode->value,
            'aspect_ratio' => $request->aspectRatio->value,
            'resolution' => $request->resolution->value,
            'seed' => $request->seed,
            'native_audio_muted' => $request->muteNativeAudio,
            'used_reference_image' => $request->references() !== [],
        ];
    }
}
