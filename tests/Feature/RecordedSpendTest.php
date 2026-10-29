<?php

namespace Tests\Feature;

use App\Contracts\Data\GeneratedMedia;
use App\Enums\AssetType;
use App\Enums\ProviderRequestStatus;
use App\Enums\ShotStatus;
use App\Integrations\Fake\FakeQueueableVideoGenerator;
use App\Models\Project;
use App\Models\ProviderRequest;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Provider\GenerationCompleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A driver prices what it generated from whatever it has to hand, and that is
 * not always the best evidence available. What finally lands in
 * usage_records.cost_usd — the only thing spentUsd() sums, and therefore the
 * only thing the cap reads — has to prefer the better number.
 */
class RecordedSpendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  float|null  $reported  what the provider claims to have charged
     */
    protected function generator(float $costUsd, ?float $reported = null): FakeQueueableVideoGenerator
    {
        return new class($costUsd, $reported) extends FakeQueueableVideoGenerator
        {
            public function __construct(protected float $stubCost, protected ?float $stubReported) {}

            public function checkStatus(string $providerRequestId): ProviderRequestStatus
            {
                return ProviderRequestStatus::Completed;
            }

            public function fetchResult(string $providerRequestId): GeneratedMedia
            {
                $path = tempnam(sys_get_temp_dir(), 'studio_stub_').'.mp4';
                file_put_contents($path, 'stand-in for a clip');

                return new GeneratedMedia(
                    path: $path,
                    mime: 'video/mp4',
                    model: 'kling-2-5-turbo-pro',
                    costUsd: $this->stubCost,
                    durationSeconds: 5.0,
                    meta: ['actual_cost_usd' => $this->stubReported],
                );
            }
        };
    }

    protected function outstandingRequest(float $estimate = 0.70): ProviderRequest
    {
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => 'kling-2-5-turbo-pro',
            'budget_cap_usd' => 10.00,
        ]);

        $scene = Scene::factory()->for($project)->create(['sequence' => 1]);

        $shot = Shot::factory()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => 'kling-2-5-turbo-pro',
            'target_duration_seconds' => 10.0,
            'status' => ShotStatus::Rendering,
        ]);

        return ProviderRequest::create([
            'project_id' => $project->getKey(),
            'capability' => 'video.clip',
            'provider' => 'fake',
            'model' => 'kling-2-5-turbo-pro',
            'fingerprint' => 'recorded-spend',
            'status' => ProviderRequestStatus::InProgress,
            'provider_request_id' => 'req-1',
            'estimated_cost_usd' => $estimate,
            'subject_type' => Shot::class,
            'subject_id' => $shot->getKey(),
        ]);
    }

    /**
     * FalVideoGenerator reads its estimate from a routing cache keyed by request
     * id and forgets the entry as soon as the file is downloaded. So a record()
     * that fails after the download — the `database is locked` this sweep has
     * already hit once on the owner's machine — leaves the NEXT sweep with a
     * cold cache and `$routing['estimate'] ?? 0.0`. A clip that cost $0.70 was
     * booked at nothing, and the cap never learned about it.
     *
     * The row has always known the number. Nothing asked it.
     */
    public function test_a_cold_routing_cache_does_not_book_a_paid_clip_at_zero(): void
    {
        $request = $this->outstandingRequest(estimate: 0.70);

        app(GenerationCompleter::class)->reconcile($request, $this->generator(costUsd: 0.0));

        $project = $request->project->fresh();

        $this->assertEqualsWithDelta(
            0.70,
            $project->spentUsd(),
            0.0001,
            'A paid clip was recorded as free because the adapter had lost its estimate.',
        );

        $this->assertEqualsWithDelta(
            0.70,
            (float) $project->assets()->where('type', AssetType::ShotClip)->first()->cost_usd,
            0.0001,
        );
    }

    /**
     * And when the provider does say what it charged, that is what the project
     * records — otherwise spentUsd() keeps an estimate for ever and the cap is
     * guarding a number nobody is being billed.
     */
    public function test_the_providers_own_figure_is_what_gets_recorded(): void
    {
        $request = $this->outstandingRequest(estimate: 0.70);

        app(GenerationCompleter::class)->reconcile(
            $request,
            $this->generator(costUsd: 0.70, reported: 0.55),
        );

        $this->assertEqualsWithDelta(0.55, $request->project->fresh()->spentUsd(), 0.0001);

        $fresh = $request->fresh();
        $this->assertEqualsWithDelta(0.55, (float) $fresh->actual_cost_usd, 0.0001);

        // The variance is the only signal that the rates in config/studio.php
        // are drifting, so it has to survive this.
        $this->assertEqualsWithDelta(-0.15, $fresh->costVarianceUsd(), 0.0001);
    }

    /**
     * A reported zero is treated as nothing reported. fal sends no cost field
     * for Kling at all, so a parsed 0.00 is far likelier to be a mapping
     * artefact than a free generation — and actual_cost_usd must stay null, or
     * costVarianceUsd() starts reporting a fictional -$0.70 of drift.
     */
    public function test_a_reported_zero_is_not_mistaken_for_a_free_generation(): void
    {
        $request = $this->outstandingRequest(estimate: 0.70);

        app(GenerationCompleter::class)->reconcile(
            $request,
            $this->generator(costUsd: 0.70, reported: 0.0),
        );

        $this->assertEqualsWithDelta(0.70, $request->project->fresh()->spentUsd(), 0.0001);
        $this->assertNull($request->fresh()->actual_cost_usd);
        $this->assertNull($request->fresh()->costVarianceUsd());
    }
}
