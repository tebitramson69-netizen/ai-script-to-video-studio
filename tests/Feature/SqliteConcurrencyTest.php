<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SQLite must not block the web process while the pipeline writes.
 *
 * Four processes write this one file — queue worker, scheduler, cache and
 * session table — and SQLite's default rollback journal lets a writer block
 * every reader. `artisan serve` on Windows is single-threaded and cannot fork,
 * so one blocked request stalls the whole UI. Observed 2026-10-06: the page
 * froze solid mid-run, and the owner could not tell a hung browser from a hung
 * pipeline while real money was in flight.
 *
 * Confirmed 2026-10-09 rather than merely argued: studio:doctor surfaced a
 * failed ReconcileProviderRequestsJob from that window carrying
 * "SQLSTATE[HY000]: General error: 5 database is locked" — SQLITE_BUSY, thrown
 * with PDO's 60-second busy timeout already in effect. SQLite skips the busy
 * handler entirely when a read lock tries to upgrade to a write against another
 * writer, which is the sweep's exact shape, so no timeout could have helped.
 *
 * WAL is not a cure for that case either — it returns SQLITE_BUSY_SNAPSHOT
 * instead, which also bypasses the handler. What WAL fixes is the FREEZE:
 * readers and writers stop blocking each other, so the page no longer waits on
 * the pipeline. Claiming more than that would be pinning a story rather than a
 * behaviour.
 *
 * The suite itself runs on :memory:, which has no journal to set, so asserting
 * on the test connection would prove nothing. These open a real file instead.
 */
class SqliteConcurrencyTest extends TestCase
{
    protected string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'studio_sqlite_').'.sqlite';
        touch($this->path);

        // The shipped sqlite connection, pointed at a file rather than memory.
        // Everything else is taken from config, so this tests what the app
        // actually uses rather than a hand-built copy of it.
        config(['database.connections.sqlite_probe' => array_merge(
            config('database.connections.sqlite'),
            ['database' => $this->path, 'url' => null],
        )]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_probe');

        // WAL leaves two sidecars beside the database.
        foreach (['', '-wal', '-shm'] as $suffix) {
            if ($this->path !== '' && file_exists($this->path.$suffix)) {
                @unlink($this->path.$suffix);
            }
        }

        parent::tearDown();
    }

    public function test_a_file_backed_database_runs_in_wal(): void
    {
        $mode = DB::connection('sqlite_probe')->select('PRAGMA journal_mode')[0]->journal_mode;

        $this->assertSame(
            'wal',
            strtolower((string) $mode),
            'Without WAL a writer blocks every reader, and the page freezes while the pipeline works.',
        );
    }

    public function test_a_collision_waits_rather_than_throwing(): void
    {
        $timeout = DB::connection('sqlite_probe')->select('PRAGMA busy_timeout')[0]->timeout;

        // PDO's own default, set explicitly so the value is visible rather
        // than inherited. It was briefly lowered to 5s; that was a mistake.
        // WAL had already fixed the freeze, so a shorter wait bought nothing
        // but a higher chance of a write failing — and AssetRecorder::record()
        // runs immediately after a paid download, where a failed write means
        // the provider was paid and the spend was never recorded.
        $this->assertSame(
            60000,
            (int) $timeout,
            'A write failing after a paid generation loses the spend record; waiting does not.',
        );
    }
}
