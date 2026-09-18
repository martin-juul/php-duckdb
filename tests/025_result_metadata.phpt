--TEST--
Result: rowCount, rowsChanged, statementType, columns()
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE m (i INTEGER, s VARCHAR)');

$result = $conn->query("INSERT INTO m VALUES (1, 'a'), (2, 'b')");
var_dump($result->rowsChanged());
var_dump($result->statementType());

$result = $conn->query('UPDATE m SET s = \'z\' WHERE i = 1');
var_dump($result->rowsChanged());

$result = $conn->query('SELECT * FROM m ORDER BY i');
var_dump($result->rowCount());
var_dump($result->statementType());
var_dump($result->columnCount());
var_dump($result->columns());
echo $result->columnName(0), ': ', $result->columnType(0), "\n";
echo $result->columnName(1), ': ', $result->columnType(1), "\n";

// Out-of-range column index
try {
    $result->columnName(9);
} catch (ValueError $e) {
    echo 'ValueError caught', "\n";
}
try {
    $result->columnType(-1);
} catch (ValueError $e) {
    echo 'ValueError caught', "\n";
}
?>
--EXPECT--
int(2)
string(6) "INSERT"
int(1)
int(2)
string(6) "SELECT"
int(2)
array(2) {
  [0]=>
  array(2) {
    ["name"]=>
    string(1) "i"
    ["type"]=>
    string(7) "INTEGER"
  }
  [1]=>
  array(2) {
    ["name"]=>
    string(1) "s"
    ["type"]=>
    string(7) "VARCHAR"
  }
}
i: INTEGER
s: VARCHAR
ValueError caught
ValueError caught
