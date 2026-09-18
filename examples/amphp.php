<?php declare(strict_types=1);

/**
 * AMPHP v3 integration: fiber-native async queries on the Revolt event loop.
 *
 * Wherever the Revolt event loop is available, PendingQuery::suspend()
 * suspends the current fiber on the loop until the query's completion
 * stream is readable — the loop keeps serving other fibers while DuckDB's
 * worker threads run the query. No configuration is needed; the driver
 * detects Revolt at runtime. Polling mode (queryPending) is driven in
 * slices with 1ms yields between them.
 *
 * Run:  composer require amphp/amp
 *       php examples/amphp.php
 */

use DuckDB\Database;
use DuckDB\FetchMode;

$autoload = getenv('DUCKDB_AMPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (!class_exists('Revolt\\EventLoop')) {
    echo "AMPHP not installed - skipping (composer require amphp/amp).\n";
    exit(0);
}

$db = new Database(':memory:');
$db->connect()->query(
    "CREATE TABLE sales AS
     SELECT range AS id, 'region-' || (range % 4) AS region, (range % 1000) * 1.5 AS amount
     FROM range(100000)"
);

// Fire three analytical queries concurrently; each suspend() suspends the
// fiber on the event loop instead of blocking the thread.
$queries = [
    'totals'  => 'SELECT sum(amount) FROM sales',
    'regions' => 'SELECT region, count(*) AS n FROM sales GROUP BY region ORDER BY n DESC LIMIT 2',
    'big'     => 'SELECT sum(t.range) AS s FROM range(50000000) t',
];

$t0 = microtime(true);
$futures = [];
foreach ($queries as $label => $sql) {
    $futures[$label] = Amp\async(function () use ($db, $sql) {
        // One connection per fiber: a connection serializes its queries by
        // design, so concurrent queries need their own.
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll(FetchMode::Assoc);
    });
}
$results = Amp\Future\await($futures);

printf("all %d queries done in %.3fs\n\n", count($queries), microtime(true) - $t0);
printf("total revenue: %.2f\n", $results['totals'][0]['sum(amount)']);
foreach ($results['regions'] as $row) {
    printf("  %-10s %d rows\n", $row['region'], $row['n']);
}
printf("big scan summed: %d\n", $results['big'][0]['s']);

// Errors surface inside the awaiting fiber as ordinary DuckDB exceptions.
try {
    Amp\async(fn() => $db->connect()->queryAsync('SELECT * FROM missing_table')->suspend())->await();
} catch (DuckDB\CatalogException $e) {
    echo 'error handling: ', $e->getMessage(), "\n";
}
