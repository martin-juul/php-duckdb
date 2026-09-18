--TEST--
Statement: binding PHP types (null/bool/int/float/string/blob/DateTime/Interval/list/struct)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Interval;

$conn = (new DuckDB\Database())->connect();

$row = $conn->execute(
    'SELECT ?::BOOLEAN AS b, ?::BIGINT AS i, ?::DOUBLE AS f, ?::VARCHAR AS s, ?::BOOLEAN AS n',
    [true, PHP_INT_MAX, 1.5, 'héllo', null]
)->fetchRow();
var_dump($row);

// BLOB via bindBlob (binary-safe)
$stmt = $conn->prepare('SELECT ?::BLOB AS blob');
$stmt->bindBlob(1, "\x00\x01\xff");
var_dump(bin2hex($stmt->execute()->fetchRow()['blob']));

// DateTimeImmutable -> TIMESTAMP (microsecond precision)
$row = $conn->execute('SELECT ?::TIMESTAMP AS ts', [new DateTimeImmutable('2024-01-01 12:00:00.123456', new DateTimeZone('UTC'))])->fetchRow();
echo $row['ts']->format('Y-m-d H:i:s.u'), "\n";

// Interval
$row = $conn->execute('SELECT ?::INTERVAL AS i', [new Interval(14, 3, 1000000)])->fetchRow();
echo $row['i']->getMonths(), ' ', $row['i']->getDays(), ' ', $row['i']->getMicros(), "\n";

// list<mixed> -> LIST
$row = $conn->execute('SELECT ?::INTEGER[] AS l', [[1, 2, null, 3]])->fetchRow();
var_dump($row['l']);

// array<string,mixed> -> STRUCT
$row = $conn->execute('SELECT ?::STRUCT(a INTEGER, b VARCHAR) AS s', [['a' => 1, 'b' => 'x']])->fetchRow();
var_dump($row['s']);

// Heterogeneous lists are rejected
try {
    $conn->execute('SELECT ?::INTEGER[] AS l', [[1, 'x']]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// Empty lists cannot be inferred
try {
    $conn->execute('SELECT ? AS l', [[]]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
array(5) {
  ["b"]=>
  bool(true)
  ["i"]=>
  int(9223372036854775807)
  ["f"]=>
  float(1.5)
  ["s"]=>
  string(6) "héllo"
  ["n"]=>
  NULL
}
string(6) "0001ff"
2024-01-01 12:00:00.123456
14 3 1000000
array(4) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  NULL
  [3]=>
  int(3)
}
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  string(1) "x"
}
ValueError: Cannot convert array to a DuckDB LIST: element 1 has a different type than the inferred element type BIGINT
ValueError: Cannot convert an empty array to a DuckDB LIST (the element type cannot be inferred)