--TEST--
Connection: getTableNames query analysis
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE alpha (i INT)');
$conn->query('CREATE TABLE beta (i INT)');

$names = $conn->getTableNames('SELECT * FROM alpha JOIN beta ON true');
sort($names);
var_dump($names);

var_dump($conn->getTableNames('SELECT * FROM (VALUES (1)) t(v)'));
?>
--EXPECT--
array(2) {
  [0]=>
  string(5) "alpha"
  [1]=>
  string(4) "beta"
}
array(0) {
}
