--TEST--
Result: DuckDB type coverage (temporal, decimal, hugeint, uuid, nested, enum, union, bit, null)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// Temporal
$row = $conn->query("SELECT DATE '2024-02-29' AS d, TIME '13:14:15' AS t, TIMESTAMP '2024-01-01 00:00:00.5' AS ts")->fetchRow();
echo $row['d']->format('Y-m-d'), "\n";
var_dump($row['t']);
echo $row['ts']->format('Y-m-d H:i:s.u'), "\n";

// Exact numerics and UUID round-trip as strings (no precision loss)
$row = $conn->query("SELECT 1.10::DECIMAL(4,2) AS dec, 340282366920938463463374607431768211455::UHUGEINT AS uh, 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'::UUID AS u, 170141183460469231731687303715884105727::HUGEINT AS h")->fetchRow();
var_dump($row);

// Nested types
$row = $conn->query("SELECT [1, 2, NULL]::INT[] AS l, {'a': 1, 'b': 'x'} AS s, MAP {'k': 42} AS m, [1,2,3]::INT[3] AS arr")->fetchRow();
var_dump($row);

// ENUM, UNION, BIT
$conn->query("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");
$row = $conn->query("SELECT 'happy'::mood AS e, 42::UNION(num INT, txt VARCHAR) AS u, '1010'::BIT AS b")->fetchRow();
var_dump($row);

// NULLs in every position
$row = $conn->query('SELECT NULL::INTEGER AS i, NULL::VARCHAR AS s, NULL::INT[] AS l')->fetchRow();
var_dump($row);
?>
--EXPECT--
2024-02-29
string(8) "13:14:15"
2024-01-01 00:00:00.500000
array(4) {
  ["dec"]=>
  string(4) "1.10"
  ["uh"]=>
  string(39) "340282366920938463463374607431768211455"
  ["u"]=>
  string(36) "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11"
  ["h"]=>
  string(39) "170141183460469231731687303715884105727"
}
array(4) {
  ["l"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    NULL
  }
  ["s"]=>
  array(2) {
    ["a"]=>
    int(1)
    ["b"]=>
    string(1) "x"
  }
  ["m"]=>
  array(1) {
    ["k"]=>
    int(42)
  }
  ["arr"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    int(2)
    [2]=>
    int(3)
  }
}
array(3) {
  ["e"]=>
  string(5) "happy"
  ["u"]=>
  int(42)
  ["b"]=>
  string(4) "1010"
}
array(3) {
  ["i"]=>
  NULL
  ["s"]=>
  NULL
  ["l"]=>
  NULL
}