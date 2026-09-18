<?php declare(strict_types=1);

/**
 * Stress: concurrent async execution and cancellation.
 *
 * Fires repeated waves of async queries through the worker pool, verifies
 * every result is correct, and cancels a subset mid-flight to exercise the
 * interrupt path. Races in worker scheduling or result ownership show up as
 * wrong row counts, hangs, or crashes.
 *
 * Exit 0 = all waves correct, 1 = incorrect result, 2 = harness error.
 */

use DuckDB\Database;
use DuckDB\PendingQuery;

if (!extension_loaded('duckdb')) {
    fwrite(STDERR, "duckdb extension not loaded\n");
    exit(2);
}

$waves = (int) (getenv('STRESS_WAVES') ?: 20);
$perWave = 8;

$db = new Database(':memory:');

for ($wave = 0; $wave < $waves; $wave++) {
    /** @var list<array{pending: PendingQuery, expect: int, cancel: bool}> $jobs */
    $jobs = [];

    for ($i = 0; $i < $perWave; $i++) {
        $conn = $db->connect();
        $limit = 100 + $i;
        $pending = $conn->queryAsync("SELECT count(*) FROM range({$limit})");
        $jobs[] = [
            'pending' => $pending,
            'expect' => $limit,
            'cancel' => ($wave % 3 === 0) && ($i % 4 === 3),
        ];
    }

    foreach ($jobs as $job) {
        if ($job['cancel']) {
            $job['pending']->cancel();
        }
    }

    foreach ($jobs as $i => $job) {
        try {
            $result = $job['pending']->await();
            $row = $result->fetchRow(\DuckDB\FetchMode::Num);
            $count = (int) ($row[0] ?? -1);
            if ($job['cancel']) {
                // A cancelled query may still finish before the cancel lands.
                if ($count !== $job['expect']) {
                    fwrite(STDERR, "wave {$wave} job {$i}: cancelled query returned {$count}\n");
                    exit(1);
                }
            } elseif ($count !== $job['expect']) {
                fwrite(STDERR, "wave {$wave} job {$i}: expected {$job['expect']}, got {$count}\n");
                exit(1);
            }
        } catch (\DuckDB\InterruptedException) {
            if (!$job['cancel']) {
                fwrite(STDERR, "wave {$wave} job {$i}: unexpected interruption\n");
                exit(1);
            }
        }
    }
}

echo "concurrency stress passed: {$waves} waves x {$perWave} queries\n";
