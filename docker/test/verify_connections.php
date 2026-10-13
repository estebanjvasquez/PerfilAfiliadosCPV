<?php

/**
 * Pre-flight guard. Runs INSIDE the test container, BEFORE any migration.
 *
 * WHY THIS IS NOT OPTIONAL
 *   phpunit.xml has the sqlite override commented out:
 *
 *       <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
 *
 *   so the suite takes DB_CONNECTION from the environment, and in normal
 *   development that resolves to the SHARED Supabase database. The historical
 *   197/197 was produced against it. Running `migrate` with the wrong
 *   resolution would therefore not fail - it would succeed, against real
 *   shared data.
 *
 *   config/database.php also has `'default' => env('DB_CONNECTION', 'mysql')`,
 *   so a missing variable silently selects a different driver rather than
 *   erroring.
 *
 * WHAT IT CHECKS
 *   Every connection Laravel has actually resolved, not just the default, and
 *   every one of them must point at the disposable fixture host. It fails
 *   closed: any doubt is an abort, and it never prints a password.
 *
 * USAGE
 *   php docker/test/verify_connections.php <expected-host> <expected-database>
 *
 * EXIT
 *   0 safe to migrate   1 unsafe, do not migrate
 */

declare(strict_types=1);

$expectedHost = $argv[1] ?? '';
$expectedDatabase = $argv[2] ?? '';

if ($expectedHost === '' || $expectedDatabase === '') {
    fwrite(STDERR, "usage: verify_connections.php <expected-host> <expected-database>\n");
    exit(1);
}

/*
 * This file is TOOLING, not part of the code under test: the runner injects it
 * into the disposable workspace so any candidate SHA can be verified, including
 * ones that predate the test runner. So the application root is discovered by
 * searching upward rather than assumed at a fixed relative depth.
 */
$root = __DIR__;
while ($root !== dirname($root) && !is_file($root . '/vendor/autoload.php')) {
    $root = dirname($root);
}
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "cannot locate vendor/autoload.php above " . __DIR__ . "
");
    exit(1);
}

require $root . '/vendor/autoload.php';

$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$problems = [];
$checked = 0;

/** Hosts that must never appear in a test run, matched loosely on purpose. */
$forbiddenHostPatterns = [
    '/supabase/i',
    '/pooler/i',
    '/amazonaws/i',
];

/*
 * WHY NON-DEFAULT CONNECTIONS ARE JUDGED DIFFERENTLY, AND WHY THIS IS NOT A
 * LOOSENING OF THE GUARD
 *
 *   The first version required EVERY pgsql/mysql connection to resolve to the
 *   fixture host. On the first otherwise-clean run it aborted on Laravel's
 *   stock `mysql` connection (127.0.0.1/forge), which nothing uses - while
 *   silently allowing `mariadb` (127.0.0.1/laravel) and `sqlsrv`
 *   (localhost/forge), because their drivers were not in the list it gated on.
 *   A rule that rejects one unused loopback placeholder and waves two others
 *   through is the wrong shape, not merely strict.
 *
 *   The rule now covers every connection and every driver. Each one must be
 *   either the fixture database, or provably unable to reach anything: a
 *   loopback placeholder with NOTHING LISTENING on its port - attempted here,
 *   not assumed - or a sqlite file inside the disposable workspace. The
 *   forbidden-host patterns and the url check now apply to all drivers instead
 *   of only two, and the sqlite path is checked where it was not checked at
 *   all before. A host that is neither the fixture nor container loopback is
 *   still an abort, and the default connection is still held to the strictest
 *   form: exact fixture host AND exact fixture database.
 */
$loopbackHosts = ['127.0.0.1', 'localhost', '::1', '[::1]', ''];
$defaultPorts = ['mysql' => 3306, 'mariadb' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433];

/** True when something actually answers on that host and port. */
$somethingListensOn = static function (string $host, int $port): bool {
    $socket = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if ($socket === false) {
        return false;
    }
    fclose($socket);

    return true;
};

echo "== Resolved database connections, before any migration\n";
echo str_repeat('-', 72) . "\n";

$environment = app()->environment();
echo sprintf("  %-22s %s\n", 'APP_ENV', $environment);
if ($environment !== 'testing') {
    $problems[] = sprintf(
        'APP_ENV is "%s", not "testing". The suite must never run outside the testing environment.',
        $environment
    );
}

$default = config('database.default');
echo sprintf("  %-22s %s\n", 'default connection', var_export($default, true));
if ($default !== 'pgsql') {
    $problems[] = sprintf(
        'default connection is %s, expected pgsql. config/database.php falls back to mysql '
        . 'when DB_CONNECTION is unset, so this usually means the variable is missing.',
        var_export($default, true)
    );
}

/*
 * EVERY configured connection, not only the default. A test that calls
 * ->connection('something') or a subprocess that resolves a legacy name would
 * otherwise escape a default-only check.
 */
foreach ((array) config('database.connections') as $name => $settings) {
    $driver = $settings['driver'] ?? '(none)';
    $host = $settings['host'] ?? '';
    $database = $settings['database'] ?? '';
    $url = $settings['url'] ?? null;
    $checked++;

    echo sprintf(
        "  %-22s driver=%-8s host=%-28s database=%s%s\n",
        $name,
        $driver,
        $host === '' ? '(none)' : $host,
        $database === '' ? '(none)' : $database,
        $url ? '  url=SET' : ''
    );

    // A url takes precedence over the discrete fields, so it could silently
    // redirect a connection that otherwise looks correct.
    if (!empty($url)) {
        $problems[] = sprintf(
            'connection "%s" has a url set, which overrides host/database and cannot be '
            . 'verified from the discrete fields. Unset it for test runs.',
            $name
        );
    }

    // Every driver, not only two: a shared host is forbidden wherever it appears.
    foreach ($forbiddenHostPatterns as $pattern) {
        if ($host !== '' && preg_match($pattern, (string) $host)) {
            $problems[] = sprintf(
                'connection "%s" resolves to host "%s", which matches a forbidden pattern. '
                . 'Shared or managed databases must never be reachable from a test run.',
                $name,
                $host
            );
        }
    }

    // The default connection is what the suite actually uses: strictest form.
    if ($name === $default) {
        if ($host !== $expectedHost) {
            $problems[] = sprintf(
                'default connection "%s" (driver %s) resolves to host "%s", expected "%s".',
                $name,
                $driver,
                $host === '' ? '(none)' : $host,
                $expectedHost
            );
        }
        if ($database !== $expectedDatabase) {
            $problems[] = sprintf(
                'default connection "%s" resolves to database "%s", expected "%s".',
                $name,
                $database === '' ? '(none)' : $database,
                $expectedDatabase
            );
        }
        continue;
    }

    // A sqlite file is only harmless while it is inside the disposable workspace.
    if ($driver === 'sqlite') {
        $path = (string) $database;
        if ($path !== ':memory:' && strpos($path, $root . '/') !== 0) {
            $problems[] = sprintf(
                'connection "%s" is a sqlite file at "%s", outside the disposable workspace %s. '
                . 'A test run must not be able to write a database file elsewhere on the host.',
                $name,
                $path === '' ? '(none)' : $path,
                $root
            );
        } else {
            echo sprintf("  %-22s sqlite path inside the workspace\n", '');
        }
        continue;
    }

    // Everything else: the fixture, or unable to reach anything at all.
    if ($host === $expectedHost) {
        continue;
    }

    $hostKey = strtolower((string) $host);
    if (!in_array($hostKey, $loopbackHosts, true)) {
        $problems[] = sprintf(
            'connection "%s" (driver %s) resolves to host "%s", which is neither the fixture '
            . 'host "%s" nor container loopback. An unused connection must still be incapable '
            . 'of reaching anything.',
            $name,
            $driver,
            $host === '' ? '(none)' : $host,
            $expectedHost
        );
        continue;
    }

    /*
     * Loopback is only safe while nothing answers there. Attempt it, so this is
     * a verified fact about this container rather than an assumption about it.
     */
    $port = (int) ($settings['port'] ?? $defaultPorts[$driver] ?? 0);
    if ($port > 0 && $hostKey !== '') {
        $probeHost = $hostKey === '[::1]' ? '::1' : $hostKey;
        if ($somethingListensOn($probeHost, $port)) {
            $problems[] = sprintf(
                'connection "%s" points at %s:%d and something IS listening there. An unused '
                . 'placeholder connection must not be able to connect to anything.',
                $name,
                $host,
                $port
            );
        } else {
            echo sprintf("  %-22s unused placeholder: nothing listening on %s:%d\n", '', $host, $port);
        }
    }
}

/*
 * Resolution is not the same as reachability. Ask the server itself where it
 * is, so a correct-looking configuration pointing somewhere unexpected is
 * still caught. Credentials are never printed.
 */
echo str_repeat('-', 72) . "\n";
try {
    $live = DB::connection()->select(
        'select current_database() as db, inet_server_addr()::text as addr, version() as version'
    );
    $row = $live[0] ?? null;
    if ($row === null) {
        $problems[] = 'the default connection returned no identity row.';
    } else {
        echo sprintf("  live current_database  %s\n", $row->db);
        echo sprintf("  live server address    %s\n", $row->addr ?? '(local socket)');
        echo sprintf("  live version           %s\n", substr((string) $row->version, 0, 48));
        if ($row->db !== $expectedDatabase) {
            $problems[] = sprintf(
                'the server reports current_database "%s", expected "%s".',
                $row->db,
                $expectedDatabase
            );
        }
    }

    // A fixture database must be empty of application tables before migrating.
    // If it is not, something other than this run has been writing to it.
    $tables = DB::connection()->select(
        "select count(*) as n from information_schema.tables where table_schema = 'public'"
    );
    $tableCount = (int) ($tables[0]->n ?? 0);
    echo sprintf("  tables in public       %d\n", $tableCount);
    if ($tableCount > 0) {
        $problems[] = sprintf(
            'the fixture database already contains %d table(s) in public. A disposable '
            . 'database must start empty; this one has state from somewhere else.',
            $tableCount
        );
    }
} catch (Throwable $e) {
    $problems[] = 'could not query the default connection: ' . $e->getMessage();
}

echo str_repeat('-', 72) . "\n";
echo sprintf("connections inspected: %d\n", $checked);

if ($problems !== []) {
    echo "\nABORTING. Do not migrate or run tests:\n";
    foreach ($problems as $problem) {
        echo '  - ' . $problem . "\n";
    }
    exit(1);
}

echo "\nSAFE: every resolved connection points at the disposable fixture database.\n";
exit(0);
