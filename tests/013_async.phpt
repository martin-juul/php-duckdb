--TEST--
PendingQuery: background execution, await, cancel
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{ErrorType, InterruptedException};

$conn = (new DuckDB\Database())->connect();

// queryAsync + await
$pending = $conn->queryAsync('SELECT 42 AS n');
var_dump($pending->getFd() >= 0);
$result = $pending->await();
var_dump($result->fetchRow());

// Statement::executeAsync
$stmt = $conn->prepare('SELECT ?::INTEGER + 1 AS n');
var_dump($stmt->executeAsync([41])->await()->fetchRow());

// A result can only be consumed once
try {
    $pending->await();
} catch (DuckDB\Exception $e) {
    echo 'twice: ', get_class($e), "\n";
}

// cancel() interrupts a running query
$pending = $conn->queryAsync('SELECT count(*) FROM range(100000000) t1(i), range(10000) t2(j)');
usleep(100000);
$pending->cancel();
try {
    $pending->await();
    echo "NOT interrupted\n";
} catch (InterruptedException $e) {
    echo 'interrupted, type=', $e->getErrorType()->name, "\n";
}

// Cancelling a finished query is a no-op (must not disturb the connection)
$pending = $conn->queryAsync('SELECT 1 AS n');
$pending->await();
$pending->cancel();
echo $conn->query('SELECT 2 AS n')->fetchRow()['n'], "\n";
?>
--EXPECT--
bool(true)
array(1) {
  ["n"]=>
  int(42)
}
array(1) {
  ["n"]=>
  int(42)
}
twice: DuckDB\Exception
interrupted, type=Interrupt
2
