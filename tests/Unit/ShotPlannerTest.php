<?php

namespace Tests\Unit;

use App\Services\Timing\NarrationEstimator;
use App\Services\Timing\ShotPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The FR-16 / FR-17 / FR-18 timing rules.
 */
class ShotPlannerTest extends TestCase
{
    protected ShotPlanner $planner;

    protected NarrationEstimator $estimator;

    /** @var list<float> */
    protected array $clipLengths = [5.0, 8.0, 10.0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new NarrationEstimator(150);
        $this->planner = new ShotPlanner($this->estimator);
    }

    public function test_it_rounds_a_short_scene_up_to_the_nearest_supported_clip_length(): void
    {
        // ~10 words at 150 wpm is ~4s, which must buy the 5s clip, not a 4s one.
        $plans = $this->planner->planNarration(
            'The river was calm and the birds were singing that morning',
            $this->clipLengths,
        );

        $this->assertCount(1, $plans);
        $this->assertSame(5.0, $plans[0]->targetDurationSeconds);
        $this->assertLessThan(5.0, $plans[0]->narrationDurationSeconds);
    }

    public function test_it_never_rounds_down_and_cuts_narration_off(): void
    {
        foreach ([1.0, 4.9, 5.0, 5.1, 7.99, 9.5] as $required) {
            $rounded = $this->planner->roundUpToSupported($required, $this->clipLengths);

            $this->assertGreaterThanOrEqual(
                $required,
                $rounded,
                "A {$required}s narration was given a {$rounded}s clip, which would truncate it.",
            );
        }
    }

    public function test_it_splits_a_scene_whose_narration_exceeds_the_longest_clip(): void
    {
        // ~60 words ≈ 26s of speech, far beyond the 10s maximum.
        $long = trim(str_repeat('The hunter walked on through the long grass and did not look back. ', 6));

        $plans = $this->planner->planNarration($long, $this->clipLengths);

        $this->assertGreaterThan(1, count($plans), 'FR-17: the scene should have been split.');

        foreach ($plans as $plan) {
            $this->assertLessThanOrEqual(
                10.0,
                $plan->narrationDurationSeconds,
                'Every split segment must fit inside one clip.',
            );
            $this->assertContains($plan->targetDurationSeconds, $this->clipLengths);
        }
    }

    public function test_splitting_preserves_all_of_the_narration(): void
    {
        $long = 'One two three four five. Six seven eight nine ten. '
            .'Eleven twelve thirteen fourteen fifteen. Sixteen seventeen eighteen nineteen twenty. '
            .'Twenty-one twenty-two twenty-three twenty-four twenty-five. '
            .'Twenty-six twenty-seven twenty-eight twenty-nine thirty.';

        $plans = $this->planner->planNarration($long, $this->clipLengths);

        $rejoined = implode(' ', array_map(fn ($p) => $p->narrationSegment, $plans));

        // No word may be dropped: narration the owner wrote must all be spoken.
        $this->assertSame(
            preg_replace('/\s+/', ' ', $long),
            preg_replace('/\s+/', ' ', $rejoined),
        );
    }

    public function test_a_sentence_longer_than_one_clip_is_split_on_word_boundaries(): void
    {
        $runOn = trim(str_repeat('and then the river rose higher ', 15));

        $plans = $this->planner->planNarration($runOn, $this->clipLengths);

        $this->assertGreaterThan(1, count($plans));

        foreach ($plans as $plan) {
            $this->assertLessThanOrEqual(10.0, $plan->narrationDurationSeconds);
        }
    }

    public function test_a_scene_with_no_narration_still_gets_one_shot(): void
    {
        $plans = $this->planner->planNarration('   ', $this->clipLengths);

        $this->assertCount(1, $plans);
        $this->assertSame(5.0, $plans[0]->targetDurationSeconds, 'Should buy the shortest clip.');
        $this->assertSame(0.0, $plans[0]->narrationDurationSeconds);
    }

    public function test_slack_reports_clip_seconds_bought_beyond_the_narration(): void
    {
        $plans = $this->planner->planNarration('A very short line.', $this->clipLengths);

        // FR-18 trims this surplus at assembly, so it is overspend worth seeing.
        $this->assertGreaterThan(0, $plans[0]->slackSeconds());
    }

    public function test_it_rejects_a_model_that_reports_no_clip_lengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->planner->planNarration('Anything at all.', []);
    }
}
