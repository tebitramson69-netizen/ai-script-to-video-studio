<?php

namespace App\Services\Provider;

use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Models\Project;
use App\Models\ProviderRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Owns idempotency for paid provider calls.
 *
 * The rule the adapter must not be trusted with: an adapter's job is to talk to
 * a provider, and it has no way to know whether this work has already been paid
 * for. So the decision "should this be submitted at all?" lives here, and the
 * adapter is only ever handed requests that genuinely need sending.
 *
 * The mechanism is a UNIQUE index on `fingerprint`, not a lookup. A lookup is
 * check-then-act: two queue workers, or one owner double-clicking, can both see
 * "nothing exists" before either inserts, and both then submit a billable job.
 * Letting the database reject the second insert is the only version that holds
 * under concurrency.
 */
class GenerationLedger
{
    /**
     * Deterministic hash of everything that decides the output.
     *
     * Anything that changes the result must be in here, or a genuine
     * regeneration would be mistaken for a duplicate and silently skipped.
     * Anything that does not must stay out, or every retry looks like new work.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function fingerprint(string $capability, string $provider, string $model, array $inputs): string
    {
        // Sort so key order in the caller cannot change the hash.
        $this->recursiveKeySort($inputs);

        return hash('sha256', json_encode([
            'capability' => $capability,
            'provider' => $provider,
            'model' => $model,
            'inputs' => $inputs,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Claim the right to submit this work, or hand back the claim someone else
     * already holds.
     *
     * `$result->isNew === true` means the caller owns it and must submit.
     * `false` means an identical request already exists — reuse its result,
     * wait for it, or report its failure, but do not pay for it twice.
     *
     * @param  array<string, mixed>  $payload  redacted request, for reproducibility
     */
    public function claim(
        Project $project,
        string $capability,
        string $provider,
        string $model,
        string $fingerprint,
        float $estimatedCostUsd,
        array $payload = [],
    ): ClaimResult {
        try {
            $request = ProviderRequest::create([
                'project_id' => $project->getKey(),
                'capability' => $capability,
                'provider' => $provider,
                'provider_model' => $model,
                'fingerprint' => $fingerprint,
                'status' => ProviderRequestStatus::Pending,
                'estimated_cost_usd' => $estimatedCostUsd,
                'request_payload' => $payload,
            ]);

            return new ClaimResult($request, isNew: true);
        } catch (UniqueConstraintViolationException) {
            // Someone got here first. That is the index doing its job, not an
            // error — the loser reuses the winner's row.
            return new ClaimResult(
                ProviderRequest::where('fingerprint', $fingerprint)->firstOrFail(),
                isNew: false,
            );
        }
    }

    /**
     * The existing request for this work, if there is one.
     */
    public function find(string $fingerprint): ?ProviderRequest
    {
        return ProviderRequest::where('fingerprint', $fingerprint)->first();
    }

    /**
     * Mark a claimed request as accepted by the provider.
     */
    public function markSubmitted(ProviderRequest $request, string $providerRequestId): ProviderRequest
    {
        $request->forceFill([
            'provider_request_id' => $providerRequestId,
            'status' => ProviderRequestStatus::InQueue,
            'submitted_at' => now(),
            'attempts' => $request->attempts + 1,
        ])->save();

        return $request;
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function markCompleted(
        ProviderRequest $request,
        ?int $assetId = null,
        ?float $actualCostUsd = null,
        ?string $outputUrl = null,
        ?array $response = null,
    ): ProviderRequest {
        $request->forceFill([
            'status' => ProviderRequestStatus::Completed,
            'asset_id' => $assetId,
            'actual_cost_usd' => $actualCostUsd,
            'output_url' => $outputUrl,
            'provider_response' => $response,
            'failure_reason' => null,
            'error_message' => null,
            'completed_at' => now(),
        ])->save();

        return $request;
    }

    public function markFailed(
        ProviderRequest $request,
        ProviderFailureReason $reason,
        string $message,
    ): ProviderRequest {
        $request->forceFill([
            'status' => ProviderRequestStatus::Failed,
            'failure_reason' => $reason,
            'error_message' => Str::limit($message, 2000),
            'completed_at' => now(),
        ])->save();

        return $request;
    }

    /**
     * @param  array<mixed>  $array
     */
    protected function recursiveKeySort(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKeySort($value);
            }
        }
    }
}
