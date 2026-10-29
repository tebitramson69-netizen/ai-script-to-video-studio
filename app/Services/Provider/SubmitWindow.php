<?php

namespace App\Services\Provider;

use App\Integrations\Fal\FalClient;

/**
 * How long a claimed-but-unsubmitted generation may sit before it counts as
 * abandoned.
 *
 * One home for one number, because getting it wrong in either direction costs
 * something different and only one of those is money.
 *
 * TOO SHORT and a submit that is still running is declared dead: the reconciler
 * releases the claim, the shot fails, the owner re-renders, and the first
 * submit lands afterwards — two clips billed for one shot. TOO LONG and a
 * genuinely orphaned claim leaves its shot stuck in "rendering" for longer than
 * necessary, which is only ever an inconvenience.
 *
 * It used to be `timeout + 60`, on the stated reasoning that "past the submit
 * timeout no POST started earlier can still be running". That reasoning missed
 * the retry: FalClient::retryWhen() returns true for a ConnectionException, and
 * a client-side timeout IS one, so the client makes up to `request_attempts`
 * attempts of `timeout_seconds` each. At the shipped 2 x 120s the real worst
 * case is ~240s against a 180s grace — a 60-second window in which a live
 * submit could be released and then resubmitted.
 *
 * So it is derived rather than chosen: every attempt may burn the full timeout,
 * the pauses between them are counted, and MARGIN_SECONDS covers queue latency
 * and clock skew between the worker that claimed and the sweep that judges it.
 */
final class SubmitWindow
{
    /**
     * Slack on top of the provider's worst case.
     *
     * Not tuned — it only has to be larger than the delay between a submit
     * finishing and the row recording it, and waiting is the cheap direction.
     */
    public const MARGIN_SECONDS = 60;

    public static function graceSeconds(): int
    {
        $timeout = (int) config('studio.fal.timeout_seconds', 120);
        $attempts = max(1, (int) config('studio.fal.request_attempts', 2));

        $sleepSeconds = (int) ceil((($attempts - 1) * FalClient::RETRY_SLEEP_MS) / 1000);

        return ($attempts * $timeout) + $sleepSeconds + self::MARGIN_SECONDS;
    }
}
