--TEST--
Database: file persistence and object lifetime
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$path = sys_get_temp_dir() . '/duckdb_phpt_' . uniqid() . '.duckdb';

$conn = (new DuckDB\Database($path))->connect();
$conn->query('CREATE TABLE t (i INT)');
$conn->query('INSERT INTO t VALUES (42)');
unset($conn);

$conn2 = (new DuckDB\Database($path))->connect();
var_dump($conn2->query('SELECT i FROM t')->fetchRow());
unset($conn2);
unlink($path);

// A connection keeps its database alive
$db = new DuckDB\Database();
$conn = $db->connect();
unset($db);
var_dump($conn->query('SELECT 1 AS n')->fetchRow());
?>
--EXPECT--
array(1) {
  ["i"]=>
  int(42)
}
array(1) {
  ["n"]=>
  int(1)
}
