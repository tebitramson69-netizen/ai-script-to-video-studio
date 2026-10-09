<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),

            /*
             * Laravel ships both of these as null, which leaves SQLite in its
             * default rollback-journal mode where a WRITER BLOCKS EVERY READER.
             * That default assumes one process. This app runs four writers
             * against the one file: the queue worker, the scheduler, the cache
             * and the session table (QUEUE_CONNECTION, CACHE_STORE and
             * SESSION_DRIVER are all `database`).
             *
             * Observed 2026-10-06 on Windows: the page froze solid while the
             * audio stage was writing assets. `artisan serve` is single-threaded
             * and cannot fork, so one request blocked on the lock stalls the
             * whole UI — and the progress strip polls, so requests pile up
             * behind it. The owner could not tell a hung browser from a hung
             * pipeline, which is the worst moment for that ambiguity: it was
             * mid-run with real money in flight.
             *
             * CONFIRMED 2026-10-09. `studio:doctor` surfaced a failed job from
             * that window — ReconcileProviderRequestsJob, 2026-10-07 03:05:02 —
             * carrying the exception this setting exists to prevent:
             *
             *     SQLSTATE[HY000]: General error: 5 database is locked
             *
             * That is SQLITE_BUSY, and it threw with PDO's 60-second busy
             * timeout ALREADY in effect. Which tells us which of these two
             * lines actually did the work.
             *
             * SQLite returns BUSY without ever invoking the busy handler when a
             * connection holding a read lock tries to upgrade to a write while
             * another is writing: a deadlock rather than congestion, so waiting
             * cannot resolve it. The sweep reads outstanding() and then writes
             * each result, which is exactly that shape. No busy timeout of any
             * length would have saved it.
             *
             * WAL is the fix for the FREEZE, because readers and writers stop
             * blocking each other and the page no longer waits on the pipeline.
             *
             * Be careful not to claim more than that. WAL does NOT make the
             * upgrade case impossible — there it returns SQLITE_BUSY_SNAPSHOT,
             * which also bypasses the busy handler. What it does is narrow the
             * window hard: the upgrade now fails only if another connection
             * actually COMMITTED since this one's read snapshot, rather than
             * whenever anyone merely holds a write lock. The real cure is
             * transaction_mode IMMEDIATE, deliberately not set here because
             * SQLiteConnection::executeBeginTransactionStatement() only honours
             * it on PHP >= 8.4 — it would apply in development and be silently
             * ignored under this repo's 8.3.0 platform pin in CI, which is worse
             * than not having it.
             *
             * busy_timeout is set to PDO's own default rather than left to be
             * inherited, so the value is visible. It was briefly lowered to 5s
             * and that was a mistake: WAL had already fixed the freeze, so the
             * only thing the shorter wait bought was a higher chance of a write
             * failing — and AssetRecorder::record() runs immediately after a
             * paid download, where a failed write means the provider was paid
             * and the spend was never recorded. A page waiting is cheap next to
             * a charge nothing knows about.
             *
             * Both are env-overridable because a deployment on MySQL or Postgres
             * never reaches this block, and a filesystem without proper locking
             * (some network shares) cannot do WAL at all.
             */
            'busy_timeout' => env('DB_BUSY_TIMEOUT', 60000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),

            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
