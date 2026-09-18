--TEST--
Connection: transaction helpers (beginTransaction/commit/rollBack)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t (i INT)');

$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1)');
$conn->rollBack();
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());

$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (2)');
$conn->commit();
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());

// Nested begin is rejected by DuckDB itself
$conn->beginTransaction();
try {
    $conn->beginTransaction();
} catch (DuckDB\TransactionException $e) {
    echo 'nested: code=', $e->getCode(), "\n";
}
$conn->rollBack();

// commit/rollBack without an active transaction
try {
    $conn->commit();
} catch (DuckDB\TransactionException $e) {
    echo 'commit: code=', $e->getCode(), "\n";
}
try {
    $conn->rollBack();
} catch (DuckDB\TransactionException $e) {
    echo 'rollback: code=', $e->getCode(), "\n";
}

// Helpers respect close()
$conn->close();
try {
    $conn->beginTransaction();
} catch (DuckDB\ConnectionException $e) {
    echo 'closed', "\n";
}
?>
--EXPECT--
array(1) {
  ["c"]=>
  int(0)
}
array(1) {
  ["c"]=>
  int(1)
}
nested: code=10
commit: code=10
rollback: code=10
closed
