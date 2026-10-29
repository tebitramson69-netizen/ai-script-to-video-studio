<?php

namespace Tests\Feature;

use App\Services\Database\ResilientWrite;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * A write that loses a race with another connection must run again.
 *
 * SQLite serialises writers and this app puts four on one file — queue, cache,
 * session and the app itself. WAL stops a writer blocking readers, which is
 * what froze the page, but it does not make a write unfailable: a transaction
 * that reads and then upgrades gets SQLITE_BUSY_SNAPSHOT when another
 * connection has committed since its snapshot, and that bypasses the busy
 * handler, so no timeout of any length helps. Three sweeps died that way on the
 * owner's machine, two of them after WAL was on.
 *
 * These exercise the retry through our own call site rather than asserting that
 * Laravel works. causedByConcurrencyError() matches on the message and not on
 * where it came from, so a closure that throws the driver's own string takes
 * exactly the path a driver-level lock takes.
 */
class ResilientWriteTest extends TestCase
{
    // Deliberately NOT RefreshDatabase. It wraps each test in a transaction,
    // which turns the one under test into a savepoint — and Laravel refuses to
    // retry a nested transaction, throwing a DeadlockException instead. The
    // first draft of this test used it and failed for that reason, which is
    // worth knowing: the retry only protects an outermost write. Nothing here
    // touches a table, so there is nothing to roll back.

    public function test_a_write_that_loses_a_race_is_retried(): void
    {
        $attempts = 0;

        $result = ResilientWrite::run(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
            }

            return 'collected';
        });

        $this->assertSame(2, $attempts, 'A lost race was not retried, so a paid generation goes uncollected.');
        $this->assertSame('collected', $result);
    }

    /**
     * The counterweight, and the more important half. A genuine bug must fail
     * once — retrying it would run the same broken write three times, and in
     * AssetRecorder's case the provider's temp file has already been moved and
     * unlinked by then.
     */
    public function test_an_ordinary_failure_is_not_retried(): void
    {
        $attempts = 0;

        try {
            ResilientWrite::run(function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('a real bug, not a race');
            });
            $this->fail('A non-concurrency failure was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('a real bug, not a race', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'A genuine bug was run more than once.');
    }

    /**
     * Telemetry must not take down the work it describes. The reconciler writes
     * its heartbeat before collecting anything, and failing the sweep because
     * that progress note lost a race turned a cosmetic problem into an
     * uncollected — already paid for — generation, plus a permanent
     * failed_jobs row, because the sweep runs with tries = 1.
     */
    public function test_a_best_effort_write_reports_failure_without_throwing(): void
    {
        $this->assertFalse(
            ResilientWrite::bestEffort(function () {
                throw new PDOException('database is locked');
            }),
            'A best-effort write must say it failed, not throw.',
        );

        $this->assertTrue(ResilientWrite::bestEffort(fn () => true));
    }
}
