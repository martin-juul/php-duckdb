<?php declare(strict_types=1);

/**
 * FrankenPHP integration: persistent worker mode.
 *
 * The extension is ZTS-safe: every PHP method runs on FrankenPHP's worker
 * thread, connections serialize their DuckDB calls internally, and async
 * worker threads never touch PHP state. Build note: FrankenPHP embeds a
 * ZTS PHP, so the extension must be compiled against the SAME PHP version
 * with ZTS enabled — see docs/frankenphp.md (the dunglas/frankenphp
 * *-builder images have everything needed).
 *
 * Worker mode specifics:
 *  - Objects kept in worker scope survive requests: a ':memory:' Database
 *    held by a worker script is a per-worker-thread in-memory cache.
 *  - One Connection per worker is enough — FrankenPHP serves one request
 *    per thread at a time, and the driver serializes calls per connection.
 *  - Abandoned async queries are safe: at shutdown the driver interrupts
 *    and waits out in-flight workers before PHP may unload the extension
 *    (tests/065_async_shutdown_segfault.phpt).
 *
 * Run:  cd examples && frankenphp run --config frankenphp.Caddyfile
 * Then: curl http://localhost:8080/
 */

use DuckDB\Database;

if (!function_exists('frankenphp_handle_request')) {
    echo "FrankenPHP not detected - skipping (this example is a worker-mode script).\n";
    echo "Run it with: cd examples && frankenphp run --config frankenphp.Caddyfile\n";
    exit(0);
}

// Worker scope: created once per worker thread, reused across requests.
// This ':memory:' database is private to its worker thread.
$db = new Database(':memory:');
$conn = $db->connect();
$conn->query('CREATE TABLE hits (path VARCHAR, n BIGINT)');

$handler = static function () use ($conn) {
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $conn->execute('INSERT INTO hits VALUES (?, 1)', [$path]);
    $total = $conn->query('SELECT sum(n) AS t FROM hits')->fetchAll()[0]['t'];

    header('Content-Type: application/json');
    echo json_encode([
        'path' => $path,
        'hits_served_by_this_worker' => $total,
    ]);
};

while (frankenphp_handle_request($handler)) {
}
