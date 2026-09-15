<?php

namespace Tests\Unit;

use App\Services\Timing\NarrationEstimator;
use PHPUnit\Framework\TestCase;

class NarrationEstimatorTest extends TestCase
{
    public function test_it_estimates_roughly_the_configured_words_per_minute(): void
    {
        $estimator = new NarrationEstimator(150);

        // 150 words with no punctuation should land near 60 seconds.
        $text = trim(str_repeat('word ', 150));

        $this->assertEqualsWithDelta(60.0, $estimator->estimateSeconds($text), 1.0);
    }

    public function test_punctuation_adds_pause_time(): void
    {
        $estimator = new NarrationEstimator(150);

        $flat = trim(str_repeat('word ', 20));
        $punctuated = trim(str_repeat('word, ', 20));

        $this->assertGreaterThan(
            $estimator->estimateSeconds($flat),
            $estimator->estimateSeconds($punctuated),
            'Commas are pauses; ignoring them under-buys clip length.',
        );
    }

    public function test_empty_text_is_zero_seconds(): void
    {
        $this->assertSame(0.0, (new NarrationEstimator(150))->estimateSeconds('   '));
    }
}
