--TEST--
Result: streaming query (constant memory, forward-only)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// Half a million rows through a streaming result
$result = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(500000) t(i)');
$count = 0;
$sum = 0;
foreach ($result as $row) {
    $count++;
    $sum += $row['i'];
}
echo "count=$count sum=$sum\n";

// Streaming results also work with fetchRow
$result = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(3) t(i)');
var_dump($result->fetchRow());
var_dump($result->fetchRow());
var_dump($result->fetchRow());
var_dump($result->fetchRow());

// Statement::executeStreaming
$stmt = $conn->prepare('SELECT i::BIGINT AS i FROM range(?) t(i)');
$result = $stmt->executeStreaming([2]);
var_dump($result->fetchAll());
?>
--EXPECT--
count=500000 sum=124999750000
array(1) {
  ["i"]=>
  int(0)
}
array(1) {
  ["i"]=>
  int(1)
}
array(1) {
  ["i"]=>
  int(2)
}
NULL
array(2) {
  [0]=>
  array(1) {
    ["i"]=>
    int(0)
  }
  [1]=>
  array(1) {
    ["i"]=>
    int(1)
  }
}
