--TEST--
Transactions: an open streaming result refuses a transaction boundary with ResourceInUse
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function refused(callable $operation): void {
    try {
        $operation();
        echo "NOT REFUSED\n";
    } catch (DuckDB\Exception $e) {
        echo $e->getErrorType()->name, ': ', $e->getMessage(), "\n";
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t (i INTEGER)');
$conn->query('INSERT INTO t SELECT range FROM range(10)');

$stream = $conn->queryStreaming('SELECT i FROM t');
var_dump($stream->fetchRow());

// BEGIN TRANSACTION is a statement on this connection: DuckDB refuses it
// while the stream is open, and the stream keeps all of its rows.
refused(fn() => $conn->beginTransaction());

$rows = 1;
while ($stream->fetchRow() !== null) {
    $rows++;
}
echo "stream rows: $rows\n";

// The refused BEGIN started nothing: ROLLBACK has no transaction to end.
try {
    $conn->rollBack();
    echo "NOT REFUSED\n";
} catch (DuckDB\TransactionException $e) {
    echo "no transaction was begun\n";
}

// The drained stream releases the connection.
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (10)');
$conn->commit();
var_dump($conn->query('SELECT count(*)::INT AS n FROM t')->fetchRow()['n']);
echo "done\n";
?>
--EXPECT--
array(1) {
  ["i"]=>
  int(0)
}
ResourceInUse: Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
stream rows: 10
no transaction was begun
int(11)
done
