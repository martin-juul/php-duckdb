--TEST--
Types: VARCHAR/BLOB binary safety (empty, NUL bytes, unicode)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$row = $conn->query("SELECT ''::VARCHAR AS empty")->fetchRow();
var_dump($row);

// Embedded NUL bytes survive the round trip (compare as hex)
$row = $conn->query("SELECT 'a' || chr(0) || 'b'::VARCHAR AS with_nul")->fetchRow();
var_dump(bin2hex($row['with_nul']));

$row = $conn->execute('SELECT ?::VARCHAR AS v', ["x\x00y\x00z"])->fetchRow();
var_dump(bin2hex($row['v']));
var_dump(strlen($row['v']));

// BLOB round-trip is byte-exact, including NUL and 0xFF
$binary = random_bytes(256);
$stmt = $conn->prepare('SELECT ?::BLOB AS b');
$stmt->bindBlob(1, $binary);
var_dump($stmt->execute()->fetchColumn() === $binary);

$row = $conn->query("SELECT '\\x00\\xff'::BLOB AS b")->fetchRow();
var_dump(bin2hex($row['b']));

// Very long string
$long = str_repeat('quack', 20000);
$row = $conn->execute('SELECT ?::VARCHAR AS v', [$long])->fetchRow();
var_dump($row['v'] === $long);

// Unicode length is measured in bytes on the wire
$row = $conn->execute('SELECT ?::VARCHAR AS v', ['héllo'])->fetchRow();
var_dump(strlen($row['v']));
?>
--EXPECT--
array(1) {
  ["empty"]=>
  string(0) ""
}
string(6) "610062"
string(10) "780079007a"
int(5)
bool(true)
string(4) "00ff"
bool(true)
int(6)
