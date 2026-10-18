--TEST--
Logging: native byte limit evicts old records and discards oversized messages
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$db = new DuckDB\Database();
$storage = new DuckDB\LogStorage(1024);
$db->registerLogStorage('bytes', $storage);
$conn = $db->connect();
$conn->query("SET logging_storage = 'bytes'");
$conn->query('SET enable_logging = true');
$storage->drain();

// Keep SQL text small so QueryLog cannot itself be the oversized entry.
$conn->query("SELECT write_log('first:' || repeat('x', 5 * 1024 * 1024))");
$conn->query("SELECT write_log('second:' || repeat('y', 5 * 1024 * 1024))");
$messages = array_column($storage->drain(), 'message');
$first = false;
$second = false;
foreach ($messages as $message) {
    $first = $first || str_starts_with($message, 'first:');
    $second = $second || str_starts_with($message, 'second:');
}
if ($first || !$second || $storage->droppedCount() < 1) {
    throw new RuntimeException('Byte limit must evict the older large entry');
}
unset($messages, $message);
echo "byte eviction\n";

$before = $storage->droppedCount();
$conn->query("SELECT write_log('oversized:' || repeat('z', 8 * 1024 * 1024))");
foreach ($storage->drain() as $entry) {
    if (str_starts_with($entry['message'], 'oversized:')) {
        throw new RuntimeException('Oversized record retained');
    }
}
if ($storage->droppedCount() <= $before) {
    throw new RuntimeException('Oversized record not counted');
}
echo "oversized discarded\n";
?>
--EXPECT--
byte eviction
oversized discarded
