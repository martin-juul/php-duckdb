--TEST--
Interval: value object semantics
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Interval;

$i = new Interval(14, 3, 1000000);
var_dump($i->getMonths(), $i->getDays(), $i->getMicros());
echo (string) $i, "\n";
echo json_encode($i), "\n";

$j = Interval::fromSeconds(90.5);
var_dump($j->getMonths(), $j->getDays(), $j->getMicros());

$neg = new Interval(0, 0, -1500000);
echo json_encode($neg), "\n";

// Extreme values do not overflow on negation
$min = new Interval(0, 0, PHP_INT_MIN);
echo json_encode($min), "\n";

// Round-trip through DuckDB
$conn = (new DuckDB\Database())->connect();
$row = $conn->execute('SELECT ?::INTERVAL AS i', [$i])->fetchRow();
echo $row['i']->getMonths(), ' ', $row['i']->getDays(), ' ', $row['i']->getMicros(), "\n";
?>
--EXPECTF--
int(14)
int(3)
int(1000000)
1 year 2 months 3 days 00:00:01
{"months":14,"days":3,"micros":1000000}
int(0)
int(0)
int(90500000)
{"months":0,"days":0,"micros":-1500000}
{"months":0,"days":0,"micros":%i}
14 3 1000000
