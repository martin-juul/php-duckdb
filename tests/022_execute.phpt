--TEST--
Connection: execute() convenience wrapper
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// No params
var_dump($conn->execute('SELECT 1 AS n')->fetchRow());

// List -> positional
var_dump($conn->execute('SELECT ?::INT + ?::INT AS n', [1, 2])->fetchRow());

// String keys -> named
var_dump($conn->execute('SELECT $a::INT + $b::INT AS n', ['a' => 10, 'b' => 32])->fetchRow());

// Multiple executions of the same prepared statement shape
$stmt = $conn->prepare('SELECT ?::INT AS n');
var_dump($stmt->execute([1])->fetchRow());
var_dump($stmt->execute([2])->fetchRow());

// Too many positional values is a programmer error (ValueError)
try {
    $conn->execute('SELECT ?::INT', [1, 2, 3]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
array(1) {
  ["n"]=>
  int(1)
}
array(1) {
  ["n"]=>
  int(3)
}
array(1) {
  ["n"]=>
  int(42)
}
array(1) {
  ["n"]=>
  int(1)
}
array(1) {
  ["n"]=>
  int(2)
}
ValueError: Too many values: statement has 1 parameter(s), 3 given
