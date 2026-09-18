<?php declare(strict_types=1);

/**
 * Swoole 6+ integration: coroutine-native async queries.
 *
 * Inside a Swoole coroutine, PendingQuery::suspend() yields the coroutine
 * on the query's completion descriptor — the event loop keeps serving other
 * coroutines while DuckDB's worker threads run the query. No configuration
 * is needed; the driver detects Swoole 6+ at runtime. (OpenSwoole is not
 * supported.)
 *
 * Run:  php examples/swoole.php   (requires ext-swoole >= 6.0)
 */

use DuckDB\Database;
use DuckDB\FetchMode;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

if (!extension_loaded('swoole') || version_compare(SWOOLE_VERSION, '6.0', '<')) {
    echo "Swoole 6+ not installed - skipping (the example needs ext-swoole >= 6.0).\n";
    exit(0);
}

run(function () {
    $db = new Database(':memory:');
    $db->connect()->query(
        "CREATE TABLE sales AS
         SELECT range AS id, 'region-' || (range % 4) AS region, (range % 1000) * 1.5 AS amount
         FROM range(100000)"
    );

    // Fire three analytical queries concurrently; each suspend() yields the
    // coroutine instead of blocking the process.
    $queries = [
        'totals'  => 'SELECT sum(amount) FROM sales',
        'regions' => 'SELECT region, count(*) AS n FROM sales GROUP BY region ORDER BY n DESC LIMIT 2',
        'big'     => 'SELECT sum(t.range) AS s FROM range(50000000) t',
    ];

    $t0 = microtime(true);
    $cids = [];
    $results = [];
    foreach ($queries as $label => $sql) {
        $cids[$label] = Coroutine::create(function () use ($db, $sql, $label, &$results) {
            // One connection per coroutine: a connection serializes its
            // queries by design, so concurrent queries need their own.
            $results[$label] = $db->connect()->queryAsync($sql)->suspend()->fetchAll(FetchMode::Assoc);
        });
    }
    Coroutine::join(array_values($cids));

    printf("all %d queries done in %.3fs\n\n", count($queries), microtime(true) - $t0);
    printf("total revenue: %.2f\n", $results['totals'][0]['sum(amount)']);
    foreach ($results['regions'] as $row) {
        printf("  %-10s %d rows\n", $row['region'], $row['n']);
    }
    printf("big scan summed: %d\n", $results['big'][0]['s']);
});
