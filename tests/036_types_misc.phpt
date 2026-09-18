--TEST--
Types: UUID, BIT, ENUM, BOOLEAN
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// UUID canonical form
$row = $conn->query("SELECT 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11'::UUID AS u")->fetchRow();
var_dump($row['u']); // normalized to lowercase

// UUID round-trip via bind
$row = $conn->execute("SELECT ?::UUID AS u", ['a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'])->fetchRow();
var_dump($row['u']);

// BIT strings preserve exact bit patterns (incl. padding handling)
$row = $conn->query("SELECT '101011'::BIT AS b6, '0'::BIT AS b1, '1'::BIT AS b1b, '000000001'::BIT AS b9")->fetchRow();
var_dump($row);

// ENUM decodes to its label
$conn->query("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");
$row = $conn->query("SELECT 'sad'::mood AS a, 'happy'::mood AS b")->fetchRow();
var_dump($row);

// ENUM in a list
var_dump($conn->query("SELECT ['sad', 'happy']::mood[] AS v")->fetchRow()['v']);

// BOOLEAN
$row = $conn->query('SELECT true AS t, false AS f, NULL::BOOLEAN AS n')->fetchRow();
var_dump($row);
?>
--EXPECT--
string(36) "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11"
string(36) "a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11"
array(4) {
  ["b6"]=>
  string(6) "101011"
  ["b1"]=>
  string(1) "0"
  ["b1b"]=>
  string(1) "1"
  ["b9"]=>
  string(9) "000000001"
}
array(2) {
  ["a"]=>
  string(3) "sad"
  ["b"]=>
  string(5) "happy"
}
array(2) {
  [0]=>
  string(3) "sad"
  [1]=>
  string(5) "happy"
}
array(3) {
  ["t"]=>
  bool(true)
  ["f"]=>
  bool(false)
  ["n"]=>
  NULL
}
