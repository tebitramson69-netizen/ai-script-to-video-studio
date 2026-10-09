<?php

namespace Tests;

use App\Services\Cost\CostEstimate;
use App\Services\Provider\DownloadUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the suite off the network.
     *
     * DownloadUrlGuard resolves asset hostnames to refuse private addresses.
     * Nineteen tests download from cdn.fal.media, and a real lookup would make
     * those depend on DNS and on this machine having a route out — slow, and
     * failing for reasons that have nothing to do with the code under test.
     *
     * The guard's own behaviour is covered directly in DownloadUrlGuardTest,
     * which injects resolvers of its own. This binding only removes DNS from
     * everything that is testing something else.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->app->bind(DownloadUrlGuard::class, fn () => new DownloadUrlGuard(
            // 93.184.216.34 is public, so hosts resolve and pass. A test that
            // wants a refusal says so explicitly with its own resolver.
            fn (string $host) => ['93.184.216.34'],
        ));
    }

    /**
     * What one stage costs in an estimate, found by the start of its label.
     *
     * Every line-item label carries its own detail — "Narration (5 request(s))",
     * "Sound effects (2 scene(s))", "Music (11.6s)" — so a test that wants the
     * number would otherwise have to spell the wording out and break whenever
     * the panel's phrasing changes. Returns null when the stage contributes no
     * line at all, which is a different assertion from costing $0.00.
     */
    protected function costOfStage(CostEstimate $estimate, string $labelPrefix): ?float
    {
        foreach ($estimate->lineItems as $label => $usd) {
            if (str_starts_with($label, $labelPrefix)) {
                return $usd;
            }
        }

        return null;
    }
}
