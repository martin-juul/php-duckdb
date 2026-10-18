--TEST--
Logging: registered native storage captures records and drains explicitly
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$db = new DuckDB\Database();
$storage = new DuckDB\LogStorage();
$db->registerLogStorage('capture', $storage);
$conn = $db->connect();
$conn->query("SET logging_storage = 'capture'");
$conn->query('SET enable_logging = true');
$conn->query("SELECT write_log('php logging sentinel')");
$entries = array_values(array_filter($storage->drain(), fn(array $entry) => $entry['message'] === 'php logging sentinel'));
check(count($entries) === 1, 'Exactly one sentinel record');
$entry = $entries[0];
check(is_int($entry['timestamp']) && abs($entry['timestamp'] - (int) (microtime(true) * 1000000)) < 600000000, 'Unix microseconds');
check(is_string($entry['level']) && $entry['level'] !== '', 'Severity');
check(is_string($entry['type']), 'Log type');
check($storage->drain() === [], 'Drain removes records');
check($storage->droppedCount() === 0, 'Nothing discarded');
echo "captured and drained\n";

$other = new DuckDB\Database();
$other->connect()->query("SELECT write_log('independent database')");
check($storage->drain() === [], 'Independent databases do not share storage');
echo "isolated\n";
?>
--EXPECT--
captured and drained
isolated
