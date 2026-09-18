<?php declare(strict_types=1);

/**
 * True Async integration: coroutine-native async queries.
 *
 * Inside an Async\ coroutine, PendingQuery::suspend() parks the coroutine in
 * the libuv reactor (via the async-aware stream_select() on the query's
 * completion descriptor) — the scheduler thread keeps running other
 * coroutines while DuckDB's worker threads run the query. No configuration
 * is needed; the driver detects the True Async runtime automatically.
 * Cancelling a coroutine cooperatively interrupts its in-flight query.
 *
 * True Async is a php-src fork, not a loadable extension:
 * https://true-async.github.io/en/ — build this driver with the fork's
 * phpize and run this example with the fork's php binary.
 *
 * Run:  php examples/true_async.php   (requires the true-async php fork)
 */

use DuckDB\Database;
use DuckDB\FetchMode;

if (!class_exists('Async\\Coroutine') || !function_exists('Async\\spawn')) {
    echo "True Async runtime not present - skipping (the example needs the true-async php-src fork).\n";
    exit(0);
}

$db = new Database(':memory:');
$db->connect()->query(
    "CREATE TABLE sales AS
     SELECT range AS id, 'region-' || (range % 4) AS region, (range % 1000) * 1.5 AS amount
     FROM range(100000)"
);

// Fire three analytical queries concurrently; each suspend() parks the
// coroutine in the reactor instead of blocking the thread.
$queries = [
    'totals'  => 'SELECT sum(amount) FROM sales',
    'regions' => 'SELECT region, count(*) AS n FROM sales GROUP BY region ORDER BY n DESC LIMIT 2',
    'big'     => 'SELECT sum(t.range) AS s FROM range(50000000) t',
];

$t0 = microtime(true);
$coroutines = [];
foreach ($queries as $label => $sql) {
    $coroutines[$label] = Async\spawn(function () use ($db, $sql) {
        // One connection per coroutine: a connection serializes its
        // queries by design, so concurrent queries need their own.
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll(FetchMode::Assoc);
    });
}
$results = Async\await_all_or_fail($coroutines);

printf("all %d queries done in %.3fs\n\n", count($queries), microtime(true) - $t0);
printf("total revenue: %.2f\n", $results['totals'][0]['sum(amount)']);
foreach ($results['regions'] as $row) {
    printf("  %-10s %d rows\n", $row['region'], $row['n']);
}
printf("big scan summed: %d\n", $results['big'][0]['s']);

// Cooperative cancellation: cancelling the coroutine interrupts the query.
$slow = Async\spawn(function () use ($db) {
    $db->connect()->queryAsync('SELECT sum(t1.range) FROM range(500000000) t1, range(50) t2')->suspend();
});
Async\delay(100);
$slow->cancel();
try {
    Async\await($slow);
    echo "cancel: query finished anyway?!\n";
} catch (\Cancellation $e) {
    echo "cancel: coroutine cancelled, DuckDB query interrupted\n";
}
