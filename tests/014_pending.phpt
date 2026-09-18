--TEST--
PendingQuery: single-threaded polling mode (queryPending)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\InterruptedException;

$conn = (new DuckDB\Database())->connect();

// Polling drives the query forward on this thread. Use a scan large enough
// (50M morsel-driven tasks) that it can never collapse into a single slice,
// even on a fast machine — a smaller query can finish in one task and make
// the first isReady() call return true, which is a timing flake, not a bug.
$pending = $conn->queryPending('SELECT count(*)::BIGINT AS c FROM range(50000000)');
var_dump($pending->getFd()); // no fd in polling mode
$ticks = 0;
while (!$pending->isReady()) {
    $ticks++;
}
var_dump($ticks > 0);
var_dump($pending->await()->fetchRow());

// Prepare errors surface eagerly (the statement cannot be created)
try {
    $conn->queryPending('SELECT * FROM missing_table');
} catch (DuckDB\CatalogException $e) {
    echo 'catalog error on prepare', "\n";
}

// cancel in polling mode
$pending = $conn->queryPending('SELECT count(*) FROM range(100000000) t1(i), range(10000) t2(j)');
$pending->cancel();
try {
    $pending->await();
    echo "NOT interrupted\n";
} catch (InterruptedException $e) {
    echo "interrupted\n";
}
?>
--EXPECT--
int(-1)
bool(true)
array(1) {
  ["c"]=>
  int(50000000)
}
catalog error on prepare
interrupted
