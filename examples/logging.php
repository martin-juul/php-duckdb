<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\{Database, LogStorage};

$db = new Database();
$storage = new LogStorage(capacity: 128);
$db->registerLogStorage('application', $storage);
$conn = $db->connect();
$conn->query("SET logging_storage = 'application'");
$conn->query('SET enable_logging = true');

// This bridge runs on the PHP thread, where an application logger can be used.
$forward = static function () use ($storage): void {
    foreach ($storage->drain() as $entry) {
        if (str_starts_with($entry['message'], 'application:')) {
            printf("%s %s\n", $entry['level'], $entry['message']);
        }
    }
};

$conn->query("SELECT write_log('application: synchronous report complete')");
$forward();

$pending = $conn->queryAsync("SELECT write_log('application: background report complete')");
while (!$pending->isReady()) {
    $forward();
    usleep(1000);
}
$pending->await();
$forward();
echo 'Discarded entries: ', $storage->droppedCount(), "\n";
