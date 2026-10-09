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
 * What each of the two settings does and does not fix — including the failed
 * sweep that proved it on 2026-10-09 — is recorded once, above the settings
 * themselves in config/database.php. Restating it here would give it two homes
 * and one of them would go stale.
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
        // than inherited. It was briefly lowered to 5s; config/database.php
        // says why that was a mistake.
        $this->assertSame(
            60000,
            (int) $timeout,
            'A write failing after a paid generation loses the spend record; waiting does not.',
        );
    }
}
