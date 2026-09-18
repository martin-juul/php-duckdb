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

// A DIFFERENT process hits the single-writer lock: duckdb_open_ext fails
// immediately with "IO Error: Could not set lock on file ...", which the
// Database constructor surfaces as a typed IOException (ErrorType::Io) -
// not a crash, and not silent sharing. This probe MUST run before any
// in-process second handle (see below): POSIX fcntl locks are per-process,
// and closing ANY descriptor a process holds on a file releases EVERY lock
// that process has on it - so destroying an in-process second handle would
// silently unlock the primary handle before the child ever ran.
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

// DuckDB's file lock is per-process: a second handle in the SAME process
// opens fine and replays the WAL, seeing everything committed so far.
// Keep this LAST of the lock-sensitive sections: unset($db2) closes its
// descriptor, which under POSIX fcntl semantics also drops the primary
// handle's lock on this file. Nothing below relies on the lock anymore.
$db2 = new Database($path);
$count = $db2->connect()->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'];
echo "second handle: opened, sees $count\n";
unset($db2);

// Once every connection and the Database itself are released, the data is
// durable and a fresh handle reads it all back.
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
child: io Io lock=yes
child exit: 0
writer still works: 4
second handle: opened, sees 4
reopened sum: 10
done
