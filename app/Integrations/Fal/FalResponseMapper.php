<?php

namespace App\Integrations\Fal;

use App\Enums\ProviderRequestStatus;

/**
 * The one place that knows what fal's JSON looks like.
 *
 * Every other class in this integration deals in request ids, statuses and
 * file paths. Field names live here alone, so when the captured shapes arrive
 * there is exactly one file to correct — and the tests that pin them are the
 * tests that fail.
 *
 * Each accessor tries the known key first and then falls back to a structural
 * search. That is not belt-and-braces for its own sake: this codebase has never
 * been able to reach fal.ai (the build environment's egress policy blocks it),
 * so the key names below are a documented pattern rather than something
 * observed. A mapper that only understood the pattern would break on the first
 * difference; one that can also recognise "a string that ends in .mp4" keeps
 * working, and the difference shows up as a failing assertion in
 * FalResponseMapperTest rather than as a broken pipeline.
 */
class FalResponseMapper
{
    /** Keys that have carried the queue handle in fal's documented responses. */
    protected const REQUEST_ID_KEYS = ['request_id', 'requestId', 'id'];

    protected const STATUS_KEYS = ['status', 'state'];

    /** Dotted paths, most specific first. */
    protected const VIDEO_PATHS = [
        'video.url',
        'output.video.url',
        'data.video.url',
        'response.video.url',
        'videos.0.url',
        'output.url',
        'data.url',
        'url',
    ];

    /**
     * @param  array<string, mixed>  $body
     */
    public function requestId(array $body): ?string
    {
        foreach (self::REQUEST_ID_KEYS as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Where fal says the request has got to.
     *
     * Matched on substrings rather than equality. The documented values are
     * IN_QUEUE / IN_PROGRESS / COMPLETED, but a provider that one day answers
     * "COMPLETED_WITH_WARNINGS" should not be read as "still running" — which
     * would leave the poller waiting forever on work that is finished and
     * already paid for.
     *
     * @param  array<string, mixed>  $body
     */
    public function status(array $body): ?ProviderRequestStatus
    {
        $raw = $this->rawStatus($body);

        if ($raw === null) {
            return null;
        }

        $state = strtoupper($raw);

        return match (true) {
            str_contains($state, 'QUEUE') => ProviderRequestStatus::InQueue,
            str_contains($state, 'PROGRESS'),
            str_contains($state, 'RUNNING'),
            str_contains($state, 'PROCESSING') => ProviderRequestStatus::InProgress,
            str_contains($state, 'CANCEL') => ProviderRequestStatus::Cancelled,
            str_contains($state, 'FAIL'),
            str_contains($state, 'ERROR') => ProviderRequestStatus::Failed,
            str_contains($state, 'COMPLET'),
            str_contains($state, 'SUCCE'),
            str_contains($state, 'OK') => ProviderRequestStatus::Completed,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function rawStatus(array $body): ?string
    {
        foreach (self::STATUS_KEYS as $key) {
            if (is_string($body[$key] ?? null)) {
                return $body[$key];
            }
        }

        return null;
    }

    /**
     * The URL fal tells us to poll, when it tells us.
     *
     * Preferred over anything this code constructs: fal's own response is
     * authoritative about its own routing, and following it sidesteps the
     * question of how a multi-segment model id maps onto a request URL.
     *
     * @param  array<string, mixed>  $body
     */
    public function statusUrl(array $body): ?string
    {
        return $this->firstUrl($body, ['status_url', 'statusUrl']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function resultUrl(array $body): ?string
    {
        return $this->firstUrl($body, ['response_url', 'responseUrl', 'result_url']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function cancelUrl(array $body): ?string
    {
        return $this->firstUrl($body, ['cancel_url', 'cancelUrl']);
    }

    /**
     * The generated file.
     *
     * @param  array<string, mixed>  $body
     */
    public function videoUrl(array $body): ?string
    {
        $flat = $this->flatten($body);

        foreach (self::VIDEO_PATHS as $path) {
            $value = $flat[$path] ?? null;

            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
            }
        }

        // Structural fallback: any http string that looks like a video file.
        foreach ($flat as $value) {
            if (is_string($value) && str_starts_with($value, 'http') && $this->looksLikeVideo($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * What fal says it charged, if it says.
     *
     * Null is a real answer and must not be coerced to 0.0: "free" and "not
     * reported" bill very differently, and the completer keeps the estimate
     * when the provider is silent rather than recording a spend of nothing.
     *
     * @param  array<string, mixed>  $body
     */
    public function actualCostUsd(array $body): ?float
    {
        foreach ($this->flatten($body) as $path => $value) {
            if (! is_int($value) && ! is_float($value) && ! $this->isNumericString($value)) {
                continue;
            }

            // Both ends anchored to a key boundary. Without the trailing one,
            // 'costume_count' reads as a price — and a wrong number here is
            // recorded as real spend against the budget cap (FR-11).
            if (preg_match('/(^|[._])(cost|price|billed|charge)s?([._]|$)/i', $path)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function durationSeconds(array $body): ?float
    {
        foreach ($this->flatten($body) as $path => $value) {
            if (! is_int($value) && ! is_float($value) && ! $this->isNumericString($value)) {
                continue;
            }

            // Anchored the same way, and milliseconds are skipped rather than
            // silently read as seconds — a 5000 would otherwise land in the
            // timeline as an 83-minute clip.
            if (preg_match('/(^|[._])(ms|millis(econds)?)([._]|$)/i', $path)) {
                continue;
            }

            if (preg_match('/(^|[._])durations?([._]|$)/i', $path) && (float) $value > 0) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Whatever fal said about why it failed.
     *
     * @param  array<string, mixed>  $body
     */
    public function errorMessage(array $body): ?string
    {
        foreach ($this->flatten($body) as $path => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (preg_match('/(^|[._])(error|detail|message|reason)/i', $path)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $keys
     */
    protected function firstUrl(array $body, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && str_starts_with($value, 'http')) {
                return $value;
            }
        }

        return null;
    }

    protected function looksLikeVideo(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|#|$)/i', $url);
    }

    protected function isNumericString(mixed $value): bool
    {
        return is_string($value) && is_numeric($value);
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    public function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
