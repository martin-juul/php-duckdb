--TEST--
Logging: capacity validation and oldest-entry eviction
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
foreach ([0, -1] as $capacity) {
    try {
        new DuckDB\LogStorage($capacity);
        throw new RuntimeException('Expected ValueError');
    } catch (ValueError $error) {
        echo "invalid capacity\n";
    }
}

$db = new DuckDB\Database();
$storage = new DuckDB\LogStorage(2);
foreach (['', "bad\0name", 'memory', 'STDOUT', 'file'] as $name) {
    try {
        $db->registerLogStorage($name, $storage);
        throw new RuntimeException('Expected invalid name ValueError');
    } catch (ValueError $error) {
        echo "invalid name\n";
    }
}

$db->registerLogStorage('bounded', $storage);
try {
    $db->registerLogStorage('bounded', new DuckDB\LogStorage());
    throw new RuntimeException('Expected duplicate name ValueError');
} catch (ValueError $error) {
    echo "duplicate name\n";
}

$conn = $db->connect();
$conn->query("SET logging_storage = 'bounded'");
$conn->query('SET enable_logging = true');
$storage->drain();
for ($i = 0; $i < 8; $i++) {
    $conn->query("SELECT write_log('bounded-$i')");
}
$entries = $storage->drain();
if (count($entries) > 2 || !in_array('bounded-7', array_column($entries, 'message'), true)) {
    throw new RuntimeException('The bounded buffer must retain the newest entry');
}
$dropped = $storage->droppedCount();
if ($dropped < 6 || $storage->drain() !== [] || $storage->droppedCount() !== $dropped) {
    throw new RuntimeException('Discard accounting survives draining');
}
echo "bounded and counted\n";
?>
--EXPECT--
invalid capacity
invalid capacity
invalid name
invalid name
invalid name
invalid name
invalid name
duplicate name
bounded and counted
