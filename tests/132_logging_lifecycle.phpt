--TEST--
Logging: shared-file database, native storage lifetime and background writes
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function captured(DuckDB\LogStorage $storage, string $message): void
{
    if (!in_array($message, array_column($storage->drain(), 'message'), true)) {
        throw new RuntimeException('Missing log entry: ' . $message);
    }
}

$path = sys_get_temp_dir() . '/duckdb_logging_' . uniqid() . '.duckdb';
$db = new DuckDB\Database($path);
$storage = new DuckDB\LogStorage();
$db->registerLogStorage('shared_capture', $storage);
$conn = $db->connect();
$conn->query("SET logging_storage = 'shared_capture'");
$conn->query('SET enable_logging = true');
$otherDb = new DuckDB\Database($path);
$other = $otherDb->connect();
$other->query("SELECT write_log('shared handle')");
captured($storage, 'shared handle');
unset($db, $otherDb, $other);
$conn->query("SELECT write_log('retained database')");
captured($storage, 'retained database');
echo "shared instance and retained database\n";

$pending = $conn->queryAsync("SELECT write_log('background writer')");
$pending->await();
captured($storage, 'background writer');
unset($pending);
$pending = $conn->queryPending("SELECT write_log('pending writer')");
$pending->await();
captured($storage, 'pending writer');
unset($pending);
$conn->prepare("SELECT write_log('prepared writer')")->execute();
captured($storage, 'prepared writer');
echo "execution paths\n";

$conn->query("SELECT write_log('after database release')");
unset($conn);
gc_collect_cycles();
captured($storage, 'after database release');
echo "drain after release\n";

for ($i = 0; $i < 40; $i++) {
    $loopDb = new DuckDB\Database();
    $loopStorage = new DuckDB\LogStorage(2);
    $loopDb->registerLogStorage('loop', $loopStorage);
    $loopConnection = $loopDb->connect();
    $loopConnection->query("SET logging_storage = 'loop'");
    $loopConnection->query('SET enable_logging = true');
    unset($loopStorage);
    $loopConnection->query("SELECT write_log('wrapper released')");
    unset($loopConnection, $loopDb);
}
echo "released wrapper loop\n";
@unlink($path);
@unlink($path . '.wal');
?>
--EXPECT--
shared instance and retained database
execution paths
drain after release
released wrapper loop
