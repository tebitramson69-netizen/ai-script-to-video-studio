<?php

namespace Tests\Feature;

use App\Enums\AspectRatio;
use App\Enums\ShotStatus;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\ShotInFlightException;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Models\User;
use App\Services\Pipeline\PipelineRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two ways a single shot regeneration could spend money it was not given.
 *
 * Deliberately in its own file rather than in RegenerationBudgetTest, which
 * skips itself when ffmpeg is absent. Neither of these needs ffmpeg, and a
 * money guard that silently skips is not a guard.
 */
class ShotRegenerationGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Regeneration always re-rolls the seed, and the seed is part of the
     * fingerprint the ledger deduplicates on (NFR-3). So reseeding a shot whose
     * provider request is still outstanding hands the ledger work it has never
     * seen, the submit goes through, and the project pays twice — once for a
     * clip no shot will ever point at.
     */
    public function test_a_shot_still_in_flight_refuses_to_regenerate(): void
    {
        foreach ([ShotStatus::Queued, ShotStatus::Rendering] as $status) {
            $project = Project::factory()->budget(50.00)->create();
            $scene = Scene::factory()->for($project)->create();

            $shot = Shot::factory()->for($project)->for($scene)->create([
                'sequence' => 1,
                'status' => $status,
                'target_duration_seconds' => 5.0,
                'prompt' => 'a riverbank at dawn',
            ]);

            $seedBefore = $shot->seed;

            try {
                app(PipelineRunner::class)->regenerateShot($project, $shot->fresh());
                $this->fail("A {$status->value} shot was allowed to regenerate and buy its clip twice.");
            } catch (ShotInFlightException $e) {
                $this->assertStringContainsString($status->value, $e->getMessage());
            }

            $shot->refresh();

            // Untouched, so the owner can wait for the render they already paid
            // for rather than find it reseeded out from under them.
            $this->assertSame($status, $shot->status);
            $this->assertSame($seedBefore, $shot->seed);
            $this->assertSame('a riverbank at dawn', $shot->prompt);
        }
    }

    /**
     * The gate must price the shot's OWN model.
     *
     * RenderShotJob charges estimateCostUsd() against the request, which
     * resolves per shot, while this gate priced everything at the project's
     * primary. A character shot on the companion was therefore gated at the
     * primary's cheaper rate, waved through, and then refused by the job —
     * with shotInvalidated() already run and the clip gone.
     *
     * Paired across two genuinely different rates, because both Kling entries
     * are $0.07/s and pairing them cannot tell a correct gate from a lazy one.
     */
    public function test_a_companion_model_shot_is_gated_at_the_companion_rate(): void
    {
        $project = Project::factory()->for(User::factory())->create([
            'video_model' => 'kling-2-5-turbo-pro',
            'video_model_i2v' => 'veo-3-1-fast',
            'aspect_ratio' => AspectRatio::Landscape->value,
            'budget_cap_usd' => 1.00,
        ]);

        $scene = Scene::factory()->for($project)->create();

        $shot = Shot::factory()->rendered()->for($project)->for($scene)->create([
            'sequence' => 1,
            'model' => 'veo-3-1-fast',
            'target_duration_seconds' => 10.0,
            'prompt' => 'a riverbank at dawn',
        ]);

        // 10s at the companion's $0.20/s is $2.00, over the $1.00 cap. At the
        // primary's $0.07/s it is $0.70, comfortably under — so a gate that
        // prices at the primary lets this through.
        $this->expectException(BudgetExceededException::class);

        app(PipelineRunner::class)->regenerateShot($project, $shot->fresh());
    }
}
