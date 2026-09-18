--TEST--
Result: fetchRow/fetchAll/fetchColumn with all fetch modes
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\FetchMode;

$conn = (new DuckDB\Database())->connect();

$result = $conn->query('SELECT 1 AS a, 2 AS b UNION ALL SELECT 3, 4 ORDER BY a');
var_dump($result->fetchRow());
var_dump($result->fetchRow(FetchMode::Num));
var_dump($result->fetchRow()); // exhausted

$result = $conn->query('SELECT 1 AS a, 2 AS b');
var_dump($result->fetchRow(FetchMode::Both));

$result = $conn->query('SELECT 1 AS a UNION ALL SELECT 2');
var_dump($result->fetchAll());
var_dump($result->fetchAll()); // already consumed

$result = $conn->query('SELECT 10 AS x, 20 AS y UNION ALL SELECT 30, 40 ORDER BY x');
var_dump($result->fetchColumn());
var_dump($result->fetchColumn(1));
var_dump($result->fetchColumn());
?>
--EXPECT--
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
}
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
NULL
array(4) {
  ["a"]=>
  int(1)
  [0]=>
  int(1)
  ["b"]=>
  int(2)
  [1]=>
  int(2)
}
array(2) {
  [0]=>
  array(1) {
    ["a"]=>
    int(1)
  }
  [1]=>
  array(1) {
    ["a"]=>
    int(2)
  }
}
array(0) {
}
int(10)
int(40)
NULL
