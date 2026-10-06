<?php

namespace Tests;

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
}
