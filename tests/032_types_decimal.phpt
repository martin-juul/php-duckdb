--TEST--
Types: DECIMAL exactness (no precision loss, all widths)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// Decimals come back as exact strings, preserving scale
$row = $conn->query("SELECT 1.10::DECIMAL(4,2) AS a, 0::DECIMAL(1,0) AS z, (-999999999999999999.999)::DECIMAL(38,3) AS big")->fetchRow();
var_dump($row);

// INT16/INT32/INT64/INT128-backed decimals all decode exactly
$row = $conn->query("SELECT 12.34::DECIMAL(4,2) AS w4, 1234.56::DECIMAL(9,2) AS w9, 123456.78::DECIMAL(18,2) AS w18, 12345678.90::DECIMAL(38,2) AS w38")->fetchRow();
var_dump($row);

// A value that would lose precision as a double does not here
$row = $conn->query("SELECT 0.1::DECIMAL(18,1) + 0.2::DECIMAL(18,1) AS exact")->fetchRow();
var_dump($row['exact']);

// Arithmetic stays exact across the wire
$row = $conn->query("SELECT (1.005::DECIMAL(10,3) * 100)::DECIMAL(12,1) AS calc")->fetchRow();
var_dump($row['calc']);
?>
--EXPECT--
array(3) {
  ["a"]=>
  string(4) "1.10"
  ["z"]=>
  string(1) "0"
  ["big"]=>
  string(23) "-999999999999999999.999"
}
array(4) {
  ["w4"]=>
  string(5) "12.34"
  ["w9"]=>
  string(7) "1234.56"
  ["w18"]=>
  string(9) "123456.78"
  ["w38"]=>
  string(11) "12345678.90"
}
string(3) "0.3"
string(5) "100.5"
