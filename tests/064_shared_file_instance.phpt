--TEST--
Database: opening the same file twice in one process shares the instance safely
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$path = sys_get_temp_dir() . '/duckdb_phpt_' . uniqid() . '.duckdb';

$dbA = new DuckDB\Database($path);
$connA = $dbA->connect();
$connA->query('CREATE TABLE t (i INTEGER)');
$connA->query('INSERT INTO t VALUES (1), (2), (3)');

// A second Database on the same file path is served from the process-wide
// instance cache: both handles share the underlying instance...
$dbB = new DuckDB\Database($path);
$connB = $dbB->connect();
var_dump($connB->query('SELECT count(*) AS c FROM t')->fetchRow());

// ...so disposing of the second handle cannot pull the file lock out from
// under the first. The first handle must stay fully functional.
unset($dbB, $connB);
gc_collect_cycles();

$connA->query('INSERT INTO t VALUES (4)');
var_dump($connA->query('SELECT count(*) AS c FROM t')->fetchRow());

unset($dbA, $connA);
gc_collect_cycles();
@unlink($path);
echo "done\n";
?>
--EXPECT--
array(1) {
  ["c"]=>
  int(3)
}
array(1) {
  ["c"]=>
  int(4)
}
done
