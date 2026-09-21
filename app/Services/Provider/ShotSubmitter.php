<?php

namespace App\Services\Provider;

use App\Contracts\Data\ClipRequest;
use App\Contracts\QueueableVideoGenerator;
use App\Enums\ShotStatus;
use App\Models\Shot;

/**
 * Submits one shot to a queueing provider and records the claim.
 *
 * The order here is the whole point: claim first, submit second. The claim is
 * what makes a duplicate impossible, so it must be committed before any money
 * can be spent. Submitting first and recording afterwards leaves a window where
 * a crash loses the request id while the provider keeps generating — and
 * charging — for work we can no longer collect.
 */
class ShotSubmitter
{
    public function __construct(
        protected GenerationLedger $ledger,
    ) {}

    /**
     * @return SubmissionOutcome what happened, so the caller can tell the owner
     */
    public function submit(Shot $shot, ClipRequest $request, QueueableVideoGenerator $video): SubmissionOutcome
    {
        $project = $shot->project;
        $capabilities = $video->capabilities();

        $fingerprint = $this->ledger->fingerprint(
            capability: 'video.clip',
            provider: $video->providerName(),
            model: $capabilities->key,
            inputs: $this->fingerprintInputs($request),
        );

        $claim = $this->ledger->claim(
            project: $project,
            capability: 'video.clip',
            provider: $video->providerName(),
            model: $capabilities->key,
            fingerprint: $fingerprint,
            estimatedCostUsd: $video->estimateCostUsd($request),
            payload: $this->redactedPayload($request),
        );

        $claim->request->forceFill([
            'subject_type' => $shot->getMorphClass(),
            'subject_id' => $shot->getKey(),
        ])->save();

        // Someone already owns this exact work.
        if (! $claim->isNew) {
            if ($claim->isAlreadyCompleted()) {
                return SubmissionOutcome::alreadyCompleted($claim->request);
            }

            if ($claim->isDuplicateInFlight()) {
                // Point the shot at the in-flight request rather than starting a
                // second one; the poller will complete both from the one result.
                $shot->forceFill(['status' => ShotStatus::Rendering])->save();

                return SubmissionOutcome::duplicateInFlight($claim->request);
            }

            // The previous attempt at identical inputs failed terminally.
            // Re-submitting the same inputs would fail the same way — a genuine
            // retry needs different inputs, which is why regeneration reseeds.
            return SubmissionOutcome::previouslyFailed($claim->request);
        }

        $providerRequestId = $video->submitClip($request);

        $this->ledger->markSubmitted($claim->request, $providerRequestId);

        $shot->forceFill([
            'status' => ShotStatus::Rendering,
            'attempts' => $shot->attempts + 1,
            'error' => null,
        ])->save();

        return SubmissionOutcome::submitted($claim->request);
    }

    /**
     * Everything that decides the output, and nothing that does not.
     *
     * Duration, resolution and the audio flag are in here because each changes
     * both the result and the price. Wall-clock time and attempt counts are not,
     * or every retry would look like new work and be paid for twice.
     *
     * @return array<string, mixed>
     */
    protected function fingerprintInputs(ClipRequest $request): array
    {
        return [
            'prompt' => $request->prompt,
            'duration' => round($request->durationSeconds, 3),
            'aspect_ratio' => $request->aspectRatio->value,
            'resolution' => $request->resolution->value,
            'mode' => $request->mode->value,
            'mute_native_audio' => $request->muteNativeAudio,
            'seed' => $request->seed,

            // Hash the reference bytes, not the path: the same image moved or
            // re-saved is the same conditioning and should not re-bill.
            'references' => array_map(
                fn (string $path) => is_file($path) ? hash_file('sha256', $path) : $path,
                $request->references(),
            ),
        ];
    }

    /**
     * Stored for reproducibility. Never contains credentials — the payload is
     * written to the database and shown in the UI.
     *
     * @return array<string, mixed>
     */
    protected function redactedPayload(ClipRequest $request): array
    {
        return [
            'prompt' => $request->prompt,
            'duration_seconds' => $request->durationSeconds,
            'aspect_ratio' => $request->aspectRatio->value,
            'resolution' => $request->resolution->value,
            'mode' => $request->mode->value,
            'generate_audio' => ! $request->muteNativeAudio,
            'seed' => $request->seed,
            'reference_count' => count($request->references()),
        ];
    }
}
