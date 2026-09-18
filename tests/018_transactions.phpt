--TEST--
Transactions: begin, commit, rollback, error states
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$conn->query('CREATE TABLE t (i INT)');
$conn->query('BEGIN TRANSACTION');
$conn->query('INSERT INTO t VALUES (1)');
$conn->query('ROLLBACK');
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());

$conn->query('BEGIN');
$conn->query('INSERT INTO t VALUES (2)');
$conn->query('COMMIT');
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());

// COMMIT outside a transaction is a TransactionException
try {
    $conn->query('COMMIT');
} catch (DuckDB\TransactionException $e) {
    echo 'code=', $e->getCode(), "\n";
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
code=10
