<?php

namespace App\Integrations\Fal;

use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Services\Provider\DownloadUrlGuard;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

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
 * The submit line is VERIFIED against the live account (2026-10-04): that
 * endpoint and payload were accepted, including a numeric `duration`, which
 * fal's own documentation describes as a string. The request URLs below are
 * reconstructions, and two things make relying on them survivable:
 *
 *   1. When the submit response carries status_url / response_url, those are
 *      used verbatim. fal is authoritative about its own routing.
 *   2. When it does not (a worker that restarted after submitting holds only
 *      the id), the URL is constructed — and because a multi-segment model id
 *      such as fal-ai/kling-video/v2.5-turbo/pro/text-to-video addresses its
 *      requests under just the first two segments, both forms are tried before
 *      giving up. A shape mismatch on one is not an error; one on all of them
 *      is. See SHAPE_MISMATCH_STATUSES: fal answers 405 rather than 404 there,
 *      which this got wrong until a live call said otherwise.
 */
class FalClient
{
    /**
     * Statuses that mean "this URL shape is wrong, try the next candidate".
     *
     * 404 was the only one here until a live call proved it insufficient.
     * fal answers a GET on the five-segment request URL with 405, because the
     * wrong shape still matches the POST-only submit route, so the provider
     * refuses the method rather than reporting a missing resource.
     *
     * Measured against the live account on 2026-10-04:
     *   GET {queue}/fal-ai/kling-video/v2.5-turbo/pro/text-to-video/requests/{id}/status
     *   -> 405 Method Not Allowed
     */
    protected const SHAPE_MISMATCH_STATUSES = [404, 405];

    public function __construct(
        protected string $apiKey,
        protected string $queueUrl,
        protected FalResponseMapper $mapper = new FalResponseMapper,
        protected int $timeoutSeconds = 120,
        protected int $connectTimeoutSeconds = 15,
    ) {}

    protected function guard(): DownloadUrlGuard
    {
        return app(DownloadUrlGuard::class);
    }

    protected function maxDownloadBytes(): int
    {
        return (int) config('studio.fal.max_download_bytes', 536870912);
    }

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
        if ($resultUrl !== null) {
            return $this->getFirstThatExists([$resultUrl]);
        }

        // Sources disagree on whether the result sits at the bare request URL
        // or at a /response suffix: fal's queue docs show the bare path, while
        // a documented submit response carries a response_url ending /response.
        // Both are tried, bare first. A wrong candidate costs one 404 on a cold
        // cache and nothing at all when the submit response gave us the URL.
        $bare = $this->requestUrls($endpoint, $requestId);

        return $this->getFirstThatExists([
            ...$bare,
            ...array_map(fn (string $url) => $url.'/response', $bare),
        ]);
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
    /**
     * Stream a generated file to local disk.
     *
     * The URL comes out of a provider response body and points at a host we
     * never authenticated to, so it is checked before it is fetched — see
     * DownloadUrlGuard. Redirects are checked too: a URL that passes and then
     * redirects to 169.254.169.254 would otherwise reach instance metadata.
     *
     * @param  int|null  $expectedBytes  What the provider said the file weighs, when it
     *                                   said. A mismatch means a truncated transfer, which
     *                                   otherwise lands as a silently corrupt asset.
     */
    public function download(string $url, string $destination, ?int $expectedBytes = null): void
    {
        $this->guard()->assertSafe($url);

        $limit = $this->maxDownloadBytes();

        try {
            $response = Http::withOptions([
                'sink' => $destination,

                // protocols rules out an https -> http downgrade on its own;
                // on_redirect applies the full guard to every hop, so a public
                // first URL cannot be used as a doorway to a private one.
                'allow_redirects' => [
                    'max' => 3,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['https'],
                    'on_redirect' => function (
                        RequestInterface $request,
                        ResponseInterface $response,
                        UriInterface $uri,
                    ): void {
                        $this->guard()->assertSafe((string) Uri::composeComponents(
                            $uri->getScheme(),
                            $uri->getAuthority(),
                            $uri->getPath(),
                            $uri->getQuery(),
                            null,
                        ));
                    },
                ],

                // Refuses an oversized body before any of it is written. Disk
                // here is a fixed allowance, and a download that fills it takes
                // the whole pipeline down, not just this asset.
                'on_headers' => function (ResponseInterface $response) use ($limit, $url): void {
                    $declared = (int) ($response->getHeaderLine('Content-Length') ?: 0);

                    if ($declared > $limit) {
                        throw ProviderException::because(
                            ProviderFailureReason::ProviderError,
                            sprintf(
                                'Refusing to download %s: it declares %d bytes, over the %d byte limit.',
                                $url,
                                $declared,
                                $limit,
                            ),
                            'fal',
                        );
                    }
                },
            ])
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)

                // The same policy as request(): this used to resend every
                // non-2xx, so one 401 cost three downloads.
                ->retry(2, 500, $this->retryWhen(), throw: false)
                ->get($url);
        } catch (ConnectionException $e) {
            $this->discardPartial($destination);

            throw ProviderException::because(
                ProviderFailureReason::NetworkError,
                "Could not download the generated file: {$e->getMessage()}",
                'fal',
                $e,
            );
        } catch (ProviderException $e) {
            // Thrown from on_headers or on_redirect, through Guzzle.
            $this->discardPartial($destination);

            throw $e;
        }

        if (! $response->successful()) {
            $this->discardPartial($destination);

            throw $this->exceptionFor($response, $url);
        }

        $this->assertFileIsUsable($destination, $url, $expectedBytes, $limit);
    }

    /**
     * @throws ProviderException
     */
    protected function assertFileIsUsable(string $destination, string $url, ?int $expectedBytes, int $limit): void
    {
        // A download that "succeeded" but wrote nothing is a failure the
        // pipeline must not carry forward as an asset.
        if (! is_file($destination) || filesize($destination) === 0) {
            $this->discardPartial($destination);

            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                'The generated file downloaded as empty.',
                'fal',
            );
        }

        $written = (int) filesize($destination);

        if ($written > $limit) {
            // Reached when the response declared no Content-Length, so
            // on_headers had nothing to judge.
            $this->discardPartial($destination);

            throw ProviderException::because(
                ProviderFailureReason::ProviderError,
                sprintf('%s wrote %d bytes, over the %d byte limit.', $url, $written, $limit),
                'fal',
            );
        }

        if ($expectedBytes !== null && $written !== $expectedBytes) {
            // Retryable: a truncated transfer is the common cause and a second
            // attempt usually completes. Keeping the short file would be worse
            // than failing - ffmpeg accepts some truncated mp4s and produces a
            // clip that is quietly wrong.
            $this->discardPartial($destination);

            throw ProviderException::because(
                ProviderFailureReason::NetworkError,
                sprintf(
                    'Downloaded %d bytes from %s but the provider said %d. Treating it as truncated.',
                    $written,
                    $url,
                    $expectedBytes,
                ),
                'fal',
            );
        }
    }

    /**
     * Remove a half-written file.
     *
     * Left in place, it is indistinguishable from a complete asset to the next
     * attempt, and the pipeline would carry it forward.
     */
    protected function discardPartial(string $destination): void
    {
        if (is_file($destination)) {
            @unlink($destination);
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

        // fal documents a model id as "namespace/model" — two segments, e.g.
        // fal-ai/fast-sdxl. Kling's is five
        // (fal-ai/kling-video/v2.5-turbo/pro/image-to-video), so which form
        // addresses its requests is genuinely ambiguous and both are tried.
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

            // Only a shape mismatch is worth trying the next candidate for. A
            // 401 or a 429 will answer identically on every URL, and retrying
            // it just doubles the rate-limit pressure.
            if (! in_array($response->status(), self::SHAPE_MISMATCH_STATUSES, true)) {
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
            ->retry(2, 500, $this->retryWhen(), throw: false);
    }

    /**
     * Retry only what a second attempt could plausibly fix.
     *
     * Without this, retry(2) resends every non-2xx. A 401 does not become
     * authorised and a 402 does not become funded by asking again, so the
     * second call is pure waste — and on a 404 or 405 it doubles the cost of
     * walking the candidate URL list, which is the normal path for a cold
     * cache rather than an error.
     */
    protected function retryWhen(): \Closure
    {
        return function (\Throwable $e): bool {
            if ($e instanceof ConnectionException) {
                return true;
            }

            $status = $e instanceof RequestException ? $e->response->status() : null;

            // Rate limits and provider-side faults are the two failures that a
            // short wait genuinely changes.
            return $status === 429 || ($status !== null && $status >= 500);
        };
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
