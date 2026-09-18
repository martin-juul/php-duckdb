--TEST--
Types: FLOAT/DOUBLE including NaN and infinities
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$row = $conn->query("SELECT 1.5::FLOAT AS f, 3.141592653589793::DOUBLE AS d")->fetchRow();
var_dump($row);

$row = $conn->query("SELECT 'NaN'::DOUBLE AS nan, 'inf'::DOUBLE AS inf, '-inf'::DOUBLE AS ninf")->fetchRow();
var_dump(is_nan($row['nan']));
var_dump($row['inf'] === INF);
var_dump($row['ninf'] === -INF);

// Round-trip through binds
$row = $conn->execute('SELECT ?::DOUBLE AS v', [NAN])->fetchRow();
var_dump(is_nan($row['v']));
$row = $conn->execute('SELECT ?::DOUBLE AS v', [INF])->fetchRow();
var_dump($row['v'] === INF);
$row = $conn->execute('SELECT ?::DOUBLE AS v', [-INF])->fetchRow();
var_dump($row['v'] === -INF);
$row = $conn->execute('SELECT ?::DOUBLE AS v', [1.5e300])->fetchRow();
var_dump($row['v']);
?>
--EXPECT--
array(2) {
  ["f"]=>
  float(1.5)
  ["d"]=>
  float(3.141592653589793)
}
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
float(1.5E+300)
