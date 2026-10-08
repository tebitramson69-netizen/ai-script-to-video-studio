<?php

namespace Tests\Feature;

use App\Contracts\SoundEffectGenerator;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sound effects are billed by the SECOND, and the pipeline asks for one effect
 * per cued scene at that scene's own length.
 *
 * fal's usage table, owner-read 2026-10-08: elevenlabs/sound-effects/v2,
 * Unit Type Seconds, $0.002, Quantity 6.00 — two effects on scenes of 2.60s and
 * 2.25s, which is ceil(2.60) + ceil(2.25). The config had modelled it as a flat
 * $0.0194 an effect, researched rather than observed.
 *
 * Flat pricing is wrong in both directions and only one of them is survivable:
 * it over-charges the 3-second ambience this project actually generates, and
 * UNDER-charges a 22-second one by more than half — and an under-estimate is
 * what lets a run past a cap it cannot afford.
 */
class SoundEffectCostTest extends TestCase
{
    use RefreshDatabase;

    protected function priceEffectsAt(float $perSecond): void
    {
        config(['studio.fake_costs.sfx_per_second_usd' => $perSecond]);
    }

    public function test_billable_seconds_are_rounded_up_to_whole_seconds(): void
    {
        $this->priceEffectsAt(0.002);

        $sfx = app(SoundEffectGenerator::class);

        // The two scenes from the measured run: 3 and 3 billable seconds.
        $this->assertSame(0.006, $sfx->costForSeconds(2.60));
        $this->assertSame(0.006, $sfx->costForSeconds(2.25));

        // Exactly a whole second is one second, not two.
        $this->assertSame(0.004, $sfx->costForSeconds(2.0));

        // Nothing on the timeline, nothing requested, nothing charged.
        $this->assertSame(0.0, $sfx->costForSeconds(0.0));
    }

    public function test_a_long_effect_costs_more_than_a_short_one(): void
    {
        $this->priceEffectsAt(0.002);

        $sfx = app(SoundEffectGenerator::class);

        // The old flat model priced both of these at $0.0194. The short one was
        // over by 3x; the long one was under by more than half.
        $this->assertSame(0.006, $sfx->costForSeconds(3.0));
        $this->assertSame(0.044, $sfx->costForSeconds(22.0));
    }

    public function test_the_estimate_reproduces_the_invoice_line(): void
    {
        $this->priceEffectsAt(0.002);

        $project = Project::factory()->budget(3.00)->create();

        // Scene 1 and scene 3 of the Step 6 script — the only two the
        // structurer cued — at the durations their narration measured.
        $this->cuedScene($project, 1, 'a flowing river with birdsong', 2.60);
        $this->cuedScene($project, 3, 'a busy open-air market', 2.25);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $effects = collect($estimate->lineItems)
            ->filter(fn ($_, string $label) => str_starts_with($label, 'Sound effects'))
            ->first();

        // 6.00 seconds at $0.002, exactly as fal billed it.
        $this->assertEqualsWithDelta(0.012, $effects, 0.000001);
    }

    public function test_a_scene_with_no_timeline_is_not_charged_for(): void
    {
        $this->priceEffectsAt(0.002);

        $project = Project::factory()->budget(3.00)->create();

        $this->cuedScene($project, 1, 'a flowing river with birdsong', 2.60);

        // Cued, but no shots — GenerateSoundEffectsJob skips it, so charging
        // for it would gate the run on money that is never spent.
        Scene::factory()->for($project)->create([
            'sequence' => 2,
            'sfx_cue' => 'wind over dry grass',
        ]);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $effects = collect($estimate->lineItems)
            ->filter(fn ($_, string $label) => str_starts_with($label, 'Sound effects'))
            ->first();

        $this->assertEqualsWithDelta(0.006, $effects, 0.000001);
    }

    protected function cuedScene(Project $project, int $sequence, string $cue, float $seconds): Scene
    {
        $scene = Scene::factory()->for($project)->create([
            'sequence' => $sequence,
            'sfx_cue' => $cue,
        ]);

        Shot::factory()->for($project)->for($scene)->create([
            'sequence' => $sequence,
            'narration_duration_seconds' => $seconds,
        ]);

        return $scene;
    }
}
