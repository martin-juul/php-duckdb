--TEST--
Types: all integer types at their boundaries
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$row = $conn->query("SELECT 127::TINYINT AS a, (-128)::TINYINT AS b, 255::UTINYINT AS c")->fetchRow();
var_dump($row);

$row = $conn->query("SELECT 32767::SMALLINT AS a, (-32768)::SMALLINT AS b, 65535::USMALLINT AS c")->fetchRow();
var_dump($row);

$row = $conn->query("SELECT 2147483647::INTEGER AS a, (-2147483648)::INTEGER AS b, 4294967295::UINTEGER AS c")->fetchRow();
var_dump($row);

$row = $conn->query("SELECT 9223372036854775807::BIGINT AS a, (-9223372036854775808)::BIGINT AS b, 18446744073709551615::UBIGINT AS c")->fetchRow();
var_dump($row);

// HUGEINT: native int when it fits, exact decimal string otherwise
$row = $conn->query("SELECT 9223372036854775807::HUGEINT AS fits, 9223372036854775808::HUGEINT AS overflow, (-170141183460469231731687303715884105728)::HUGEINT AS min_h")->fetchRow();
var_dump($row);

// UHUGEINT max is always a string (2^128 - 1)
$row = $conn->query("SELECT 340282366920938463463374607431768211455::UHUGEINT AS max_uh")->fetchRow();
var_dump($row);
?>
--EXPECT--
array(3) {
  ["a"]=>
  int(127)
  ["b"]=>
  int(-128)
  ["c"]=>
  int(255)
}
array(3) {
  ["a"]=>
  int(32767)
  ["b"]=>
  int(-32768)
  ["c"]=>
  int(65535)
}
array(3) {
  ["a"]=>
  int(2147483647)
  ["b"]=>
  int(-2147483648)
  ["c"]=>
  int(4294967295)
}
array(3) {
  ["a"]=>
  int(9223372036854775807)
  ["b"]=>
  int(-9223372036854775808)
  ["c"]=>
  string(20) "18446744073709551615"
}
array(3) {
  ["fits"]=>
  int(9223372036854775807)
  ["overflow"]=>
  string(19) "9223372036854775808"
  ["min_h"]=>
  string(40) "-170141183460469231731687303715884105728"
}
array(1) {
  ["max_uh"]=>
  string(39) "340282366920938463463374607431768211455"
}
