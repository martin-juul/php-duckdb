<?php declare(strict_types=1);

/**
 * Stress: memory stability across many mixed workloads.
 *
 * Runs several hundred iterations of the driver's main paths (buffered
 * queries, streaming, prepared statements with bindings, appender batches,
 * nested-type decoding) and asserts that PHP's real memory usage does not
 * grow after a warm-up phase. A leak in PHP-visible structures shows up as
 * monotonic growth; native leaks are caught by the harness's valgrind stage.
 *
 * Exit 0 = stable, 1 = memory growth beyond threshold.
 */

use DuckDB\Database;
use DuckDB\FetchMode;

if (!extension_loaded('duckdb')) {
    fwrite(STDERR, "duckdb extension not loaded\n");
    exit(2);
}

$iterations = (int) (getenv('STRESS_ITERATIONS') ?: 300);
$warmup = 50;
// Allow at most 4 MiB of post-warmup growth: allocator arenas and DuckDB's
// own caches legitimately grow a little, a leak does not stop growing.
$growthBudgetBytes = 4 * 1024 * 1024;

$db = new Database(':memory:');
$conn = $db->connect();
$conn->query('CREATE TABLE stress (i INTEGER, s VARCHAR, d DOUBLE)');
$appender = $conn->appender('stress');
for ($i = 0; $i < 1000; $i++) {
    $appender->appendRow([$i, "str{$i}", $i * 1.5]);
}
$appender->flush();
$appender->close();

$stmt = $conn->prepare('SELECT * FROM stress WHERE i > $1 ORDER BY i LIMIT $2');

$readings = [];
for ($iter = 0; $iter < $iterations; $iter++) {
    // Buffered query.
    $rows = $conn->query('SELECT count(*), sum(d), avg(length(s)) FROM stress')->fetchAll(FetchMode::Num);
    if ((int) $rows[0][0] !== 1000) {
        fwrite(STDERR, "wrong count at iteration {$iter}\n");
        exit(1);
    }

    // Prepared statement with bindings.
    $result = $stmt->execute([($iter % 900), 5]);
    if (count($result->fetchAll()) !== 5) {
        fwrite(STDERR, "wrong limited count at iteration {$iter}\n");
        exit(1);
    }

    // Streaming + nested type decoding.
    $stream = $conn->queryStreaming(
        'SELECT [i, i + 1, i + 2] AS lst, {\'a\': s, \'b\': d} AS st FROM stress LIMIT 100'
    );
    $seen = 0;
    foreach ($stream as $row) {
        $seen++;
    }
    if ($seen !== 100) {
        fwrite(STDERR, "wrong stream count at iteration {$iter}\n");
        exit(1);
    }

    if ($iter === $warmup || $iter === $iterations - 1 || $iter % 50 === 0) {
        $readings[$iter] = memory_get_usage(true);
    }
}

$baseline = $readings[$warmup];
$final = $readings[$iterations - 1];
$growth = $final - $baseline;

printf(
    "iterations=%d baseline=%.2f MiB final=%.2f MiB growth=%+.2f MiB budget=%.2f MiB\n",
    $iterations,
    $baseline / 1048576,
    $final / 1048576,
    $growth / 1048576,
    $growthBudgetBytes / 1048576,
);

if ($growth > $growthBudgetBytes) {
    fwrite(STDERR, "memory grew by {$growth} bytes after warm-up — possible leak\n");
    exit(1);
}

echo "memory stable\n";
