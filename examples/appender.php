<?php
// Bulk inserts with the Appender API.

use DuckDB\Database;

require __DIR__ . '/bootstrap.php';

$db = new Database(':memory:');
$conn = $db->connect();

$conn->query("CREATE TABLE sensor_readings (
    sensor_id  INTEGER,
    reading_at TIMESTAMP DEFAULT current_timestamp,
    value      DOUBLE
)");

$appender = $conn->appender('sensor_readings');

$start = hrtime(true);

// Whole-row appends
for ($i = 0; $i < 100_000; $i++) {
    $appender->appendRow([$i % 100, new DateTimeImmutable(), sin($i) * 100]);
}

// Piecemeal rows, using the column default for reading_at
$appender->beginRow();
$appender->append(42);
$appender->appendDefault();   // DEFAULT current_timestamp kicks in
$appender->append(3.5);
$appender->endRow();

$appender->close();           // flushes and invalidates (idempotent)

$elapsed = (hrtime(true) - $start) / 1e6;
$row = $conn->query('SELECT count(*)::INT AS n, round(avg(value), 4) AS avg_value FROM sensor_readings')->fetchRow();
printf("appended %d rows in %.1f ms (avg value %.4f)\n", $row['n'], $elapsed, $row['avg_value']);
