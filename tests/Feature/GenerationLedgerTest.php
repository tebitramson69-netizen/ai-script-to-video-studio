<?php

namespace Tests\Feature;

use App\Enums\ProviderFailureReason;
use App\Enums\ProviderRequestStatus;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ProviderRequest;
use App\Services\Provider\GenerationLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NFR-3, enforced at the database rather than in application logic.
 */
class GenerationLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected GenerationLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(GenerationLedger::class);
    }

    public function test_identical_inputs_produce_an_identical_fingerprint(): void
    {
        $a = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['prompt' => 'a river', 'seed' => 7]);
        $b = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['prompt' => 'a river', 'seed' => 7]);

        $this->assertSame($a, $b);
    }

    public function test_key_order_does_not_change_the_fingerprint(): void
    {
        $a = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['prompt' => 'a river', 'seed' => 7]);
        $b = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 7, 'prompt' => 'a river']);

        // Otherwise a harmless refactor of the caller silently re-bills everything.
        $this->assertSame($a, $b);
    }

    public function test_anything_that_changes_the_output_changes_the_fingerprint(): void
    {
        $base = ['prompt' => 'a river', 'seed' => 7, 'duration' => 8.0, 'audio' => false];
        $original = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', $base);

        foreach ([
            'prompt' => 'a mountain',
            'seed' => 8,
            'duration' => 5.0,
            'audio' => true,
        ] as $key => $changed) {
            $this->assertNotSame(
                $original,
                $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', [...$base, $key => $changed]),
                "Changing {$key} must produce a different fingerprint, or a real regeneration gets skipped.",
            );
        }

        // Model and provider matter too — the same prompt on a different model
        // is different work at a different price.
        $this->assertNotSame($original, $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1-fast', $base));
        $this->assertNotSame($original, $this->ledger->fingerprint('video.clip', 'replicate', 'veo-3.1', $base));
    }

    public function test_the_first_claim_wins_and_the_second_reuses_it(): void
    {
        $project = Project::factory()->create();
        $fingerprint = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]);

        $first = $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);
        $second = $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);

        $this->assertTrue($first->isNew);
        $this->assertFalse($second->isNew, 'A second claim on the same work must not be granted.');
        $this->assertTrue($second->request->is($first->request));

        // The row count is the real assertion: one paid call, not two.
        $this->assertSame(1, ProviderRequest::count());
    }

    public function test_a_duplicate_claim_is_reported_as_in_flight(): void
    {
        $project = Project::factory()->create();
        $fingerprint = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]);

        $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);
        $second = $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);

        $this->assertTrue($second->isDuplicateInFlight());
        $this->assertFalse($second->isAlreadyCompleted());
    }

    public function test_a_duplicate_of_completed_work_offers_the_existing_asset(): void
    {
        $project = Project::factory()->create();
        $fingerprint = $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]);

        $claim = $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);
        $asset = Asset::factory()->for($project)->create();
        $this->ledger->markCompleted($claim->request, assetId: $asset->id, actualCostUsd: 1.55);

        $second = $this->ledger->claim($project, 'video.clip', 'fal', 'veo-3.1', $fingerprint, 1.60);

        $this->assertTrue($second->isAlreadyCompleted());
        $this->assertSame($asset->id, $second->request->asset_id);
    }

    public function test_different_work_is_never_blocked(): void
    {
        $project = Project::factory()->create();

        foreach ([1, 2, 3] as $seed) {
            $this->ledger->claim(
                $project, 'video.clip', 'fal', 'veo-3.1',
                $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => $seed]),
                1.60,
            );
        }

        $this->assertSame(3, ProviderRequest::count());
    }

    public function test_actual_cost_is_recorded_separately_from_the_estimate(): void
    {
        $project = Project::factory()->create();
        $claim = $this->ledger->claim(
            $project, 'video.clip', 'fal', 'veo-3.1',
            $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]),
            1.60,
        );

        $this->ledger->markCompleted($claim->request, actualCostUsd: 1.72);
        $request = $claim->request->fresh();

        // Keeping both is what lets the config rates be corrected from reality.
        $this->assertEqualsWithDelta(1.60, (float) $request->estimated_cost_usd, 0.0001);
        $this->assertEqualsWithDelta(1.72, (float) $request->actual_cost_usd, 0.0001);
        $this->assertEqualsWithDelta(0.12, $request->costVarianceUsd(), 0.0001);
        $this->assertEqualsWithDelta(1.72, $request->effectiveCostUsd(), 0.0001);
    }

    public function test_effective_cost_falls_back_to_the_estimate_before_reconciliation(): void
    {
        $project = Project::factory()->create();
        $claim = $this->ledger->claim(
            $project, 'video.clip', 'fal', 'veo-3.1',
            $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]),
            1.60,
        );

        $this->assertEqualsWithDelta(1.60, $claim->request->effectiveCostUsd(), 0.0001);
        $this->assertNull($claim->request->costVarianceUsd());
    }

    public function test_a_safety_rejection_is_recorded_as_not_retryable(): void
    {
        $project = Project::factory()->create();
        $claim = $this->ledger->claim(
            $project, 'video.clip', 'fal', 'veo-3.1',
            $this->ledger->fingerprint('video.clip', 'fal', 'veo-3.1', ['seed' => 1]),
            1.60,
        );

        $this->ledger->markFailed($claim->request, ProviderFailureReason::ContentRejected, 'blocked by safety filter');
        $request = $claim->request->fresh();

        $this->assertSame(ProviderRequestStatus::Failed, $request->status);
        $this->assertSame(ProviderFailureReason::ContentRejected, $request->failure_reason);
        $this->assertFalse($request->isRetryable(), 'Retrying a safety rejection fails identically and may still be billed.');
        $this->assertTrue($request->failure_reason->isOwnerActionable());
    }

    public function test_outstanding_scope_returns_only_unfinished_work(): void
    {
        $project = Project::factory()->create();

        foreach ([
            ProviderRequestStatus::Pending,
            ProviderRequestStatus::InQueue,
            ProviderRequestStatus::InProgress,
            ProviderRequestStatus::Completed,
            ProviderRequestStatus::Failed,
        ] as $i => $status) {
            ProviderRequest::create([
                'project_id' => $project->id,
                'capability' => 'video.clip',
                'provider' => 'fal',
                'fingerprint' => "fp-{$i}",
                'status' => $status,
            ]);
        }

        // This is the poller's working set; a completed job must never be in it.
        $this->assertSame(3, ProviderRequest::query()->outstanding()->count());
    }
}
