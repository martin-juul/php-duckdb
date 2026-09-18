--TEST--
Database: open, connect, close (in-memory)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$db = new DuckDB\Database();
$conn = $db->connect();
var_dump($conn->isClosed());

$result = $conn->query('SELECT 42 AS answer');
var_dump($result->fetchRow());

$conn->close();
var_dump($conn->isClosed());
$conn->close(); // idempotent
var_dump($conn->isClosed());

try {
    $conn->query('SELECT 1');
} catch (DuckDB\ConnectionException $e) {
    echo 'closed: ', $e->getMessage(), "\n";
    echo 'code: ', $e->getCode(), "\n";
}

// The default path is :memory:
$db2 = new DuckDB\Database(':memory:');
echo $db2->connect()->query('SELECT 1 AS one')->fetchRow()['one'], "\n";
?>
--EXPECT--
bool(false)
array(1) {
  ["answer"]=>
  int(42)
}
bool(true)
bool(true)
closed: Connection is closed
code: 21
1
