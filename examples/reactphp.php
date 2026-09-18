<?php declare(strict_types=1);

/**
 * ReactPHP integration (react/async v4+): fiber-based async queries.
 *
 * With react/async v4+ loadable, PendingQuery::suspend() awaits a promise
 * on the ReactPHP event loop that resolves when the query's completion
 * stream becomes readable — the loop keeps serving other events while
 * DuckDB's worker threads run the query. No configuration is needed; the
 * driver detects ReactPHP at runtime (v3 is deliberately not engaged).
 * Cancelling the surrounding async() promise interrupts the query.
 *
 * Run:  composer require react/async
 *       php examples/reactphp.php
 */

use DuckDB\Database;
use DuckDB\FetchMode;
use React\EventLoop\Loop;
use function React\Async\async;
use function React\Async\await;

$autoload = getenv('DUCKDB_REACTPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists('React\\Async\\SimpleFiber')) {
    echo "ReactPHP react/async v4+ not installed - skipping (composer require react/async:^4.0).\n";
    exit(0);
}

$db = new Database(':memory:');
$db->connect()->query(
    "CREATE TABLE sales AS
     SELECT range AS id, 'region-' || (range % 4) AS region, (range % 1000) * 1.5 AS amount
     FROM range(100000)"
);

// Fire three analytical queries concurrently; each suspend() awaits on the
// event loop instead of blocking the thread.
$queries = [
    'totals'  => 'SELECT sum(amount) FROM sales',
    'regions' => 'SELECT region, count(*) AS n FROM sales GROUP BY region ORDER BY n DESC LIMIT 2',
    'big'     => 'SELECT sum(t.range) AS s FROM range(50000000) t',
];

$t0 = microtime(true);
$promises = [];
foreach ($queries as $label => $sql) {
    $promises[$label] = async(function () use ($db, $sql) {
        // One connection per fiber: a connection serializes its queries by
        // design, so concurrent queries need their own.
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll(FetchMode::Assoc);
    })();
}
$results = [];
foreach ($promises as $label => $promise) {
    $results[$label] = await($promise);
}

printf("all %d queries done in %.3fs\n\n", count($queries), microtime(true) - $t0);
printf("total revenue: %.2f\n", $results['totals'][0]['sum(amount)']);
foreach ($results['regions'] as $row) {
    printf("  %-10s %d rows\n", $row['region'], $row['n']);
}
printf("big scan summed: %d\n", $results['big'][0]['s']);

// Cooperative cancellation: cancelling the async() promise interrupts the
// in-flight DuckDB query and rejects with a RuntimeException.
$heavy = async(function () use ($db) {
    $db->connect()->queryAsync('SELECT sum(t1.range) FROM range(500000000) t1, range(50) t2')->suspend();
})();
Loop::addTimer(0.1, static function () use ($heavy): void {
    $heavy->cancel();
});
try {
    await($heavy);
    echo "cancel: query finished anyway?!\n";
} catch (RuntimeException $e) {
    echo "cancel: promise cancelled, DuckDB query interrupted\n";
}
