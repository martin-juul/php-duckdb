--TEST--
Transactions: a transaction boundary invalidates an open streaming result
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t (i INTEGER)');
$conn->query('INSERT INTO t SELECT range FROM range(10)');

$stream = $conn->queryStreaming('SELECT i FROM t');
var_dump($stream->fetchRow());

// BEGIN TRANSACTION is an execution on this connection: like every other
// execution it must invalidate the open stream (fail loudly, never
// silently truncate).
$conn->beginTransaction();
try {
    // Rows from the first chunk are already buffered, so drain the buffer:
    // the exception fires when fetchRow() has to pull a fresh chunk.
    while ($stream->fetchRow() !== null) {
    }
    echo "NOT DETECTED\n";
} catch (DuckDB\Exception $e) {
    echo "stale stream detected\n";
}
$conn->rollBack();
echo "done\n";
?>
--EXPECT--
array(1) {
  ["i"]=>
  int(0)
}
stale stream detected
done
