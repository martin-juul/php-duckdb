--TEST--
Database: file-backed concurrency — MVCC between connections, file lock, reopen
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\IOException;

$path = sys_get_temp_dir() . '/duckdb_phpt_' . uniqid() . '.duckdb';

$db = new Database($path);
$w = $db->connect(); // writer connection
$r = $db->connect(); // reader connection on the same Database

$w->query('CREATE TABLE t (i INTEGER)');
$w->query('INSERT INTO t VALUES (1), (2)');

// Snapshot isolation: the reader must not see the writer's uncommitted row,
// while the writer sees its own transaction.
$w->beginTransaction();
$w->query('INSERT INTO t VALUES (3)');
echo 'reader during tx: ', $r->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'], "\n";
echo 'writer during tx: ', $w->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'], "\n";
$w->commit();
echo 'reader after commit: ', $r->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'], "\n";

// duckdb_open_ext uses no instance cache, so a second handle on the same
// file hits DuckDB's single-writer lock and must surface as a typed
// IOException, not a crash or silent sharing.
try {
    new Database($path);
    echo "second handle: NO ERROR (unexpected)\n";
} catch (IOException $e) {
    echo 'second handle: IOException type=', $e->getErrorType()->name,
         ' lock=', stripos($e->getMessage(), 'lock') !== false ? 'mentioned' : 'absent', "\n";
}

// Once every connection and the Database itself are released, the file
// lock is gone and the data is durable.
$w->close();
$r->close();
unset($w, $r, $db);

$conn = (new Database($path))->connect();
echo 'reopened sum: ', $conn->query('SELECT sum(i)::INTEGER AS s FROM t')->fetchRow()['s'], "\n";
$conn->close();

unlink($path);
@unlink($path . '.wal');
echo "done\n";
?>
--EXPECT--
reader during tx: 2
writer during tx: 3
reader after commit: 3
second handle: IOException type=Io lock=mentioned
reopened sum: 6
done
