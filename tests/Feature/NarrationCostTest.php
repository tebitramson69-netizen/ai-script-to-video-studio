<?php

namespace Tests\Feature;

use App\Contracts\Data\SpeechModelCapabilities;
use App\Contracts\SpeechSynthesizer;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\Cost\CostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Narration is billed per REQUEST, and the pipeline makes one per shot.
 *
 * Measured against fal's usage page on 2026-10-08, after the first full run:
 * five Kokoro calls of 20-40 characters each were billed $0.10 — five times the
 * $0.02 unit rate, not the $0.0031 that 155 characters at $0.02/1k comes to.
 * The estimator had summed the project's characters and divided once.
 *
 * $0.06 is nothing; the shape is not. The error scales with shot count and runs
 * in the dangerous direction — a cap that under-estimates lets through a run it
 * cannot afford. Nothing in the suite caught it, because the fake driver's TTS
 * rate defaults to $0.00 and zero divided any way is still zero. So these tests
 * price the fake deliberately, the way BudgetCapTest prices video.
 */
class NarrationCostTest extends TestCase
{
    use RefreshDatabase;

    protected function priceSpeechAt(float $per1kCharacters): void
    {
        config(['studio.fake_costs.tts_per_1k_chars_usd' => $per1kCharacters]);
    }

    public function test_one_request_costs_a_whole_unit_however_short_it_is(): void
    {
        $model = new SpeechModelCapabilities(
            key: 'kokoro',
            label: 'Kokoro TTS (fal)',
            endpoint: null,
            endpoints: ['en' => 'fal-ai/kokoro/american-english'],
            costPer1kCharactersUsd: 0.02,
        );

        // The real shape: 39 characters billed as a full unit.
        $this->assertSame(0.02, $model->costForCharacters(39));

        // Nothing spoken, nothing requested, nothing charged.
        $this->assertSame(0.0, $model->costForCharacters(0));

        // Exactly one unit is one unit, not two.
        $this->assertSame(0.02, $model->costForCharacters(1000));

        // Past the unit it rounds up, which is the over-estimate side.
        $this->assertSame(0.04, $model->costForCharacters(1001));
        $this->assertSame(0.04, $model->costForCharacters(1500));
    }

    public function test_the_fake_driver_bills_the_same_shape_as_the_real_one(): void
    {
        $this->priceSpeechAt(0.02);

        $speech = app(SpeechSynthesizer::class);

        // A fake that prices work differently from the provider it stands in for
        // teaches the budget cap the wrong lesson.
        $this->assertSame(0.02, $speech->costForCharacters(39));
        $this->assertSame(0.04, $speech->costForCharacters(1500));
        $this->assertSame(0.0, $speech->costForCharacters(0));
    }

    public function test_the_estimate_counts_one_call_per_shot_not_the_character_total(): void
    {
        $this->priceSpeechAt(0.02);

        $project = Project::factory()->budget(3.00)->create();
        $scene = Scene::factory()->for($project)->create();

        // The Step 6 script: five shots, 155 characters between them.
        $segments = [
            'The river wakes before the village does.',
            'Doors open. The day begins.',
            'Traders call across the stalls.',
            'The path narrows into shade.',
            'Evening settles on the hills.',
        ];

        foreach ($segments as $i => $text) {
            Shot::factory()->for($project)->for($scene)->create([
                'sequence' => $i + 1,
                'narration_segment' => $text,
            ]);
        }

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $narration = $this->costOfStage($estimate, 'Narration');

        // Five requests at $0.02. Summing 155 characters first gives $0.0031,
        // which is what fal's invoice proved wrong.
        $this->assertEqualsWithDelta(0.10, $narration, 0.0001);
    }

    public function test_a_wordless_beat_is_not_charged_for(): void
    {
        $this->priceSpeechAt(0.02);

        $project = Project::factory()->budget(3.00)->create();
        $scene = Scene::factory()->for($project)->create();

        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 1, 'narration_segment' => 'Evening settles on the hills.']);

        // GenerateNarrationJob gives this one local silence, not a paid call.
        Shot::factory()->for($project)->for($scene)
            ->create(['sequence' => 2, 'narration_segment' => '']);

        $estimate = app(CostEstimator::class)->estimateRemainingRun($project->fresh());

        $narration = $this->costOfStage($estimate, 'Narration');

        $this->assertEqualsWithDelta(0.02, $narration, 0.0001);
    }
}
