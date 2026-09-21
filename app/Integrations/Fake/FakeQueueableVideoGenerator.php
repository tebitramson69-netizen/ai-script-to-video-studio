<?php

namespace App\Integrations\Fake;

use App\Contracts\Data\ClipRequest;
use App\Contracts\Data\GeneratedMedia;
use App\Contracts\ProviderException;
use App\Contracts\QueueableVideoGenerator;
use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The local renderer, pretending to be a queue.
 *
 * It exists so the submit → poll → collect lifecycle can be exercised end to
 * end before any real provider is wired up. A fake that completed instantly
 * would leave the interesting states — in-queue, in-progress, a worker restart
 * between submission and collection — completely untested, and those are
 * exactly the states that lose track of work a provider is already charging
 * for.
 *
 * `studio.fake_queue.polls_before_complete` controls how many status checks it
 * makes you wait, so a test can assert the pipeline genuinely waits.
 */
class FakeQueueableVideoGenerator extends FakeVideoGenerator implements QueueableVideoGenerator
{
    public function submitClip(ClipRequest $request): string
    {
        $id = 'fake-req-'.Str::uuid();

        // The render really happens, just not where the caller can see it —
        // same as a provider. Stored so fetchResult() returns the clip that
        // this exact request produced.
        $media = parent::generateClip($request);

        Cache::put("fake-queue:{$id}", [
            'path' => $media->path,
            'mime' => $media->mime,
            'model' => $media->model,
            'cost' => $media->costUsd,
            'duration' => $media->durationSeconds,
            'meta' => $media->meta,
            'polls' => 0,
        ], now()->addHour());

        return $id;
    }

    public function checkStatus(string $providerRequestId): ProviderRequestStatus
    {
        $entry = Cache::get("fake-queue:{$providerRequestId}");

        if ($entry === null) {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Unknown request id {$providerRequestId}.",
                $this->providerName(),
            );
        }

        $required = (int) config('studio.fake_queue.polls_before_complete', 0);

        $entry['polls']++;
        Cache::put("fake-queue:{$providerRequestId}", $entry, now()->addHour());

        if ($entry['polls'] <= $required) {
            return $entry['polls'] === 1
                ? ProviderRequestStatus::InQueue
                : ProviderRequestStatus::InProgress;
        }

        return ProviderRequestStatus::Completed;
    }

    public function fetchResult(string $providerRequestId): GeneratedMedia
    {
        $entry = Cache::get("fake-queue:{$providerRequestId}");

        if ($entry === null) {
            throw ProviderException::because(
                ProviderFailureReason::InvalidRequest,
                "Unknown request id {$providerRequestId}.",
                $this->providerName(),
            );
        }

        Cache::forget("fake-queue:{$providerRequestId}");

        return new GeneratedMedia(
            path: $entry['path'],
            mime: $entry['mime'],
            model: $entry['model'],
            costUsd: $entry['cost'],
            durationSeconds: $entry['duration'],

            // A real provider reports what it charged; the completer reads
            // actual_cost_usd from here to reconcile against the estimate.
            meta: [...$entry['meta'], 'actual_cost_usd' => $entry['cost']],
        );
    }
}
