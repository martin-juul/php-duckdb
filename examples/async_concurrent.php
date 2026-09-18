<?php
// Async concurrent queries — runs queries on background threads and waits
// for both with plain stream_select (no framework needed).

use DuckDB\Database;

require __DIR__ . '/bootstrap.php';

function awaitAll(array $pendings): void
{
    $streams = [];
    foreach ($pendings as $i => $p) {
        $streams[$i] = $p->getStream();
    }
    while ($streams) {
        $read = array_values($streams);
        $write = $except = null;
        stream_select($read, $write, $except, null);
        foreach ($streams as $i => $s) {
            if (in_array($s, $read, true) && $pendings[$i]->isReady()) {
                unset($streams[$i]);
            }
        }
    }
}

$db = new Database(':memory:');

$start = microtime(true);

$p1 = $db->connect()->queryAsync('SELECT sum(i) AS s FROM range(100000000) t(i)');
$p2 = $db->connect()->queryAsync('SELECT avg(i) AS a FROM range(100000000) t(i)');

awaitAll([$p1, $p2]);

printf("sum = %s\n", $p1->await()->fetchRow()['s']);
printf("avg = %s\n", $p2->await()->fetchRow()['a']);
printf("elapsed: %.3fs (queries ran in parallel)\n", microtime(true) - $start);
