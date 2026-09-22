<?php

namespace App\Integrations\Fal;

use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP transport for fal's queue API.
 *
 * Knows about URLs, headers, retries and what an HTTP status code means. Knows
 * nothing about video, shots or prompts — that is FalVideoGenerator's job, and
 * keeping them apart is what makes a second fal-hosted capability (image, music or
 * speech) a new adapter rather than a new client.
 *
 * The queue pattern it implements:
 *
 *   POST {queue}/{model}                          -> {request_id, status_url, response_url}
 *   GET  {queue}/{model}/requests/{id}/status     -> {status: IN_QUEUE|IN_PROGRESS|COMPLETED}
 *   GET  {queue}/{model}/requests/{id}            -> the model output
 *
 * UNVERIFIED from this codebase — fal.ai is unreachable through the build
 * environment's egress policy, so these URLs come from fal's published pattern
 * and not from an observed call. Two things make that survivable:
 *
 *   1. When the submit response carries status_url / response_url, those are
 *      used verbatim. fal is authoritative about its own routing.
 *   2. When it does not (a worker that restarted after submitting holds only
 *      the id), the URL is constructed — and because a multi-segment model id
 *      such as fal-ai/kling-video/v2.5-turbo/pro/text-to-video is documented to
 *      address its requests under just the first two segments, both forms are
 *      tried before giving up. A 404 on one is not an error; a 404 on all of
 *      them is.
 */
class FalClient
{
    public function __construct(
        protected string $apiKey,
        protected string $queueUrl,
        protected FalResponseMapper $mapper = new FalResponseMapper,
        protected int $timeoutSeconds = 120,
        protected int $connectTimeoutSeconds = 15,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiKey: (string) config('studio.fal.key', ''),
            queueUrl: rtrim((string) config('studio.fal.queue_url', 'https://queue.fal.run'), '/'),
            timeoutSeconds: (int) config('studio.fal.timeout_seconds', 120),
            connectTimeoutSeconds: (int) config('studio.fal.connect_timeout_seconds', 15),
        );
    }

    public function hasKey(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function mapper(): FalResponseMapper
    {
        return $this->mapper;
    }

    /**
     * Hand work to the queue. Returns the decoded submit response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function submit(string $endpoint, array $payload): array
    {
        $url = $this->queueUrl.'/'.trim($endpoint, '/');

        return $this->decode($this->send('POST', $url, $payload), $url);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $endpoint, string $requestId, ?string $statusUrl = null): array
    {
        return $this->getFirstThatExists(
            $statusUrl !== null
                ? [$statusUrl]
                : array_map(fn (string $base) => $base.'/status', $this->requestUrls($endpoint, $requestId)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function result(string $endpoint, string $requestId, ?string $resultUrl = null): array
    {
        return $this->getFirstThatExists(
            $resultUrl !== null ? [$resultUrl] : $this->requestUrls($endpoint, $requestId),
        );
    }

    /**
     * Best-effort cancel. Never throws: it is called when something has already
     * gone wrong, and a failure to cancel must not mask the original fault.
     */
    public function cancel(string $endpoint, string $requestId, ?string $cancelUrl = null): bool
    {
        $urls = $cancelUrl !== null
            ? [$cancelUrl]
            : array_map(fn (string $base) => $base.'/cancel', $this->requestUrls($endpoint, $requestId));

        foreach ($urls as $url) {
            try {
                if ($this->send('PUT', $url)->successful()) {
                    return true;
                }
            } catch (ProviderException) {
                // Fall through to the next candidate URL.
            }
        }

        return false;
    }

    /**
     * Stream a generated file to local disk.
     *
     * Streamed rather than held in memory: a 10-second 1080p clip is tens of
     * megabytes, and the queue worker handling it has no reason to carry that
     * as a PHP string.
     */
    public function download(string $url, string $destination): void
    {
        try {
            $response = Http::withOptions(['sink' => $destination])
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->retry(2, 500, throw: false)
                ->get($url);
        } catch (ConnectionException $e) {
            throw ProviderException::because(
                ProviderFailureReason::NetworkError,
                "Could not download the generated file: {$e->getMessage()}",
                'fal',
                $e,
            );
        }

        if (! $response->successful()) {
            throw $this->exceptionFor($response, $url);
        }

        // A download that "succeeded" but wrote nothing is a failure the
        // pipeline must not carry forward as an asset.
        if (! is_file($destination) || filesize($destination) === 0) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'The generated file downloaded as empty.',
                'fal',
            );
        }
    }

    /**
     * Candidate request URLs for an id, most specific first.
     *
     * @return list<string>
     */
    public function requestUrls(string $endpoint, string $requestId): array
    {
        $endpoint = trim($endpoint, '/');
        $id = rawurlencode($requestId);

        $urls = [$this->queueUrl."/{$endpoint}/requests/{$id}"];

        $segments = explode('/', $endpoint);

        if (count($segments) > 2) {
            $urls[] = $this->queueUrl.'/'.implode('/', array_slice($segments, 0, 2))."/requests/{$id}";
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, mixed>
     */
    protected function getFirstThatExists(array $urls): array
    {
        $last = null;

        foreach ($urls as $url) {
            $response = $this->send('GET', $url);

            if ($response->successful()) {
                return $this->decode($response, $url);
            }

            // Only a 404 is worth trying the next shape for. A 401 or a 429
            // will answer identically on every URL, and retrying it just
            // doubles the rate-limit pressure.
            if ($response->status() !== 404) {
                throw $this->exceptionFor($response, $url);
            }

            $last = $response;
        }

        throw $this->exceptionFor($last, end($urls) ?: '');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function send(string $method, string $url, ?array $payload = null): Response
    {
        try {
            return $this->request()->send($method, $url, $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException $e) {
            throw ProviderException::because(
                ProviderFailureReason::NetworkError,
                "{$method} {$url} never reached fal: {$e->getMessage()}",
                'fal',
                $e,
            );
        }
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders(['Authorization' => "Key {$this->apiKey}"])
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)

            // throw: false because classification below is richer than an
            // exception on any non-2xx: a 402 and a 429 need opposite handling.
            ->retry(2, 500, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response, string $url): array
    {
        if (! $response->successful()) {
            throw $this->exceptionFor($response, $url);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                "fal returned a non-JSON body from {$url}.",
                'fal',
            );
        }

        return $body;
    }

    /**
     * Turn an HTTP failure into the taxonomy the queue acts on.
     *
     * The classification is the point: retrying a 402 waits for money that will
     * not appear, and not retrying a 429 throws away work that would have
     * succeeded a second later.
     */
    protected function exceptionFor(?Response $response, string $url): ProviderException
    {
        if ($response === null) {
            return ProviderException::because(
                ProviderFailureReason::ProviderError,
                "No response from fal for {$url}.",
                'fal',
            );
        }

        $status = $response->status();
        $body = is_array($response->json()) ? $response->json() : [];
        $detail = $this->mapper->errorMessage($body) ?? trim($response->body());

        $reason = match (true) {
            $status === 401, $status === 403 => ProviderFailureReason::Authentication,
            $status === 402 => ProviderFailureReason::InsufficientCredit,
            $status === 408, $status === 504 => ProviderFailureReason::Timeout,
            $status === 429 => ProviderFailureReason::RateLimited,
            $status >= 500 => ProviderFailureReason::ProviderError,
            $this->looksLikeContentRejection($detail) => ProviderFailureReason::ContentRejected,
            $status >= 400 => ProviderFailureReason::InvalidRequest,
            default => ProviderFailureReason::Unknown,
        };

        return ProviderException::because(
            $reason,
            sprintf('fal returned %d for %s: %s', $status, $url, $this->truncate($detail)),
            'fal',
        );
    }

    /**
     * A refused prompt is a 4xx like any other, but it is the owner's to fix
     * and retrying it is guaranteed to fail — so it is worth telling apart,
     * even though the only signal is the wording.
     */
    protected function looksLikeContentRejection(string $detail): bool
    {
        return (bool) preg_match(
            '/(content[_ -]?polic|safety|nsfw|moderat|prohibit|not allowed|violat)/i',
            $detail,
        );
    }

    protected function truncate(string $text, int $limit = 400): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
