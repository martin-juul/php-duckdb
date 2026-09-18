--TEST--
Statement: positional parameters (?, $1)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$stmt = $conn->prepare('SELECT ?::INTEGER AS a, ?::VARCHAR AS b');
var_dump($stmt->parameterCount());
var_dump($stmt->parameterName(1));

$stmt->bindValue(1, 42);
$stmt->bindValue(2, 'hello');
var_dump($stmt->execute()->fetchRow());

// Re-execute with new values
var_dump($stmt->execute([100, 'world'])->fetchRow());

// $1 style
$stmt = $conn->prepare('SELECT $1::INTEGER + $2::INTEGER AS sum');
var_dump($stmt->execute([2, 40])->fetchRow());

// Out-of-range position
try {
    $stmt->bindValue(3, 1);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// clearBindings resets values; executing with unbound params fails
$stmt->clearBindings();
try {
    $stmt->execute();
} catch (DuckDB\Exception $e) {
    echo get_class($e), "\n";
}
?>
--EXPECTF--
int(2)
string(1) "1"
array(2) {
  ["a"]=>
  int(42)
  ["b"]=>
  string(5) "hello"
}
array(2) {
  ["a"]=>
  int(100)
  ["b"]=>
  string(5) "world"
}
array(1) {
  ["sum"]=>
  int(42)
}
ValueError: %s
DuckDB\Exception
