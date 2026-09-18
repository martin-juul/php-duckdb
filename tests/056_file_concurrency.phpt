--TEST--
Database: file-backed concurrency — MVCC, in-process handle, cross-process lock
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip cannot locate duckdb.so for the subprocess');
}
?>
--FILE--
<?php
use DuckDB\Database;

require_once __DIR__ . '/subprocess.inc';

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

// DuckDB's file lock is per-process: a second handle in the SAME process
// opens fine and replays the WAL, seeing everything committed so far.
$db2 = new Database($path);
$count = $db2->connect()->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'];
echo "second handle: opened, sees $count\n";
unset($db2);

// A DIFFERENT process hits the single-writer lock and must get a typed
// IOException, not a crash or silent sharing.
$childCode =
    'try { new DuckDB\\Database(' . var_export($path, true) . '); echo "opened\n"; }'
    . ' catch (DuckDB\\IOException $e) {'
    . ' echo "io ", $e->getErrorType()->name,'
    . ' " lock=", stripos($e->getMessage(), "lock") !== false ? "yes" : "no", "\n"; }';
$proc = proc_open(
    array_merge([PHP_BINARY], duckdb_subprocess_args(), ['-r', $childCode]),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
fclose($pipes[0]);
$childOut = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
$childExit = proc_close($proc);
echo 'child: ', trim((string) $childOut), "\n";
echo "child exit: $childExit\n";

// The failed foreign open did not disturb the writer
$w->query('INSERT INTO t VALUES (4)');
echo 'writer still works: ', $w->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'], "\n";

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
second handle: opened, sees 3
child: io Io lock=yes
child exit: 0
writer still works: 4
reopened sum: 10
done
