<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A write that may lose a race with another connection, run again if it does.
 *
 * SQLite serialises writers, and this app puts four of them on one file: the
 * queue worker, the scheduler, the cache and the session table
 * (QUEUE_CONNECTION, CACHE_STORE and SESSION_DRIVER are all `database`). WAL
 * stops a writer blocking readers, which is what froze the page — but it does
 * not make a write unfailable. A transaction that reads and then upgrades to a
 * write gets SQLITE_BUSY_SNAPSHOT if another connection committed since its
 * snapshot, and that bypasses the busy handler entirely, so no timeout of any
 * length helps. Observed three times on the owner's machine, twice with WAL
 * already on.
 *
 * Retrying is the matched remedy for precisely that case: the second attempt
 * begins from a fresh snapshot. Laravel already knows the failure —
 * ConcurrencyErrorDetector matches 'database is locked' — so DB::transaction()
 * does the work; this exists so the decision has one home and a name, rather
 * than a bare integer repeated at every call site.
 *
 * THE CALLBACK MUST BE SAFE TO RUN MORE THAN ONCE. A retry re-runs the whole
 * closure, so anything outside the database — moving a file, deleting a temp
 * path, calling a provider — belongs outside it, not in it.
 *
 * AND IT ONLY RETRIES AT THE OUTERMOST LEVEL. Called inside an existing
 * transaction this becomes a savepoint, and Laravel refuses to retry a nested
 * one — ManagesTransactions::handleTransactionException() throws a
 * DeadlockException instead, on the reasoning that the outer transaction may
 * already be poisoned. Every current caller runs outside a transaction; a
 * future one that does not gets no protection and no warning, which is why it
 * is written down here. It is also why this class's own test cannot use
 * RefreshDatabase, since that wraps each test in a transaction.
 */
final class ResilientWrite
{
    /**
     * Three, not more. A snapshot conflict clears on the next attempt or it is
     * not a snapshot conflict; more attempts would only prolong a genuine
     * problem while holding a worker.
     */
    public const ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    public static function run(\Closure $write): mixed
    {
        return DB::transaction($write, self::ATTEMPTS);
    }

    /**
     * For a write whose failure must not take the surrounding work down with it.
     *
     * Telemetry, mostly: the reconciler's heartbeat says whether the scheduler
     * is alive, and failing the sweep because its own progress note lost a race
     * would turn a cosmetic problem into an uncollected — and already paid for
     * — generation. Returns whether it succeeded, so a caller that wants to say
     * something about it can.
     */
    public static function bestEffort(\Closure $write): bool
    {
        try {
            self::run($write);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
