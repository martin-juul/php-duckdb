--TEST--
Binding: round-trips for every supported PHP type (edge values)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Interval;

$conn = (new DuckDB\Database())->connect();

// Integer extremes
var_dump($conn->execute('SELECT ?::BIGINT AS v', [PHP_INT_MAX])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::BIGINT AS v', [PHP_INT_MIN])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::BIGINT AS v', [0])->fetchRow()['v']);

// Booleans
var_dump($conn->execute('SELECT ?::BOOLEAN AS v', [true])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::BOOLEAN AS v', [false])->fetchRow()['v']);

// Float extremes
var_dump($conn->execute('SELECT ?::DOUBLE AS v', [PHP_FLOAT_EPSILON])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::DOUBLE AS v', [-1.5e-300])->fetchRow()['v']);

// Strings
var_dump($conn->execute('SELECT ?::VARCHAR AS v', [''])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::VARCHAR AS v', ['0'])->fetchRow()['v']);

// NULL
var_dump($conn->execute('SELECT ?::INTEGER AS v', [null])->fetchRow()['v']);

// Interval round-trip incl. negative micros
$i = new Interval(-2, -7, -1500000);
$row = $conn->execute('SELECT ?::INTERVAL AS v', [$i])->fetchRow();
echo $row['v']->getMonths(), ' ', $row['v']->getDays(), ' ', $row['v']->getMicros(), "\n";

// Nested bind round-trip
var_dump($conn->execute('SELECT ?::INT[][] AS v', [[[1], [2, 3]]])->fetchRow()['v']);
var_dump($conn->execute('SELECT ?::STRUCT(a INT, b INT[]) AS v', [['a' => 5, 'b' => [1, 2]]])->fetchRow()['v']);

// Struct member may be NULL
var_dump($conn->execute('SELECT ?::STRUCT(a INT, b INT) AS v', [['a' => 1, 'b' => null]])->fetchRow()['v']);

// Numeric string binds as VARCHAR, cast by SQL to HUGEINT (exact)
var_dump($conn->execute('SELECT ?::HUGEINT AS v', ['170141183460469231731687303715884105727'])->fetchRow()['v']);
?>
--EXPECTF--
int(9223372036854775807)
int(-9223372036854775808)
int(0)
bool(true)
bool(false)
float(2.220446049250313E-16)
float(-1.5E-300)
string(0) ""
string(1) "0"
NULL
-2 -7 -1500000
array(2) {
  [0]=>
  array(1) {
    [0]=>
    int(1)
  }
  [1]=>
  array(2) {
    [0]=>
    int(2)
    [1]=>
    int(3)
  }
}
array(2) {
  ["a"]=>
  int(5)
  ["b"]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
}
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  NULL
}
string(39) "170141183460469231731687303715884105727"
