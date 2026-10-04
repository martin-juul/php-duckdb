<?php
// Persist a daily summary, then reopen it with a read-only reporting connection.

use DuckDB\Database;

require __DIR__ . '/bootstrap.php';

$directory = sys_get_temp_dir() . '/duckdb-reports-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Unable to create the report directory');
}

$path = $directory . '/reports.duckdb';
$database = $connection = null;
try {
    $database = new Database($path, ['threads' => 2, 'memory_limit' => '256MB']);
    $connection = $database->connect();
    $connection->query('CREATE TABLE daily_sales(day DATE PRIMARY KEY, orders INTEGER, revenue DECIMAL(12,2))');
    $connection->execute('INSERT INTO daily_sales VALUES (?, ?, ?)', [
        new DuckDB\Date('2026-10-03'),
        12,
        new DuckDB\Decimal('349.95', precision: 12, scale: 2),
    ]);
    // Closing a connection alone does not release its database owner.
    // Release both before reopening the same file with different configuration.
    $connection->close();
    unset($connection, $database);

    $database = new Database($path, ['access_mode' => 'read_only']);
    $connection = $database->connect();
    $report = $connection->query('SELECT day::VARCHAR AS day, orders, revenue FROM daily_sales')->fetchRow();
    if ($report !== ['day' => '2026-10-03', 'orders' => 12, 'revenue' => '349.95']) {
        throw new RuntimeException('Reopened report did not preserve exact values');
    }

    echo 'reopened daily report: ', json_encode($report, JSON_THROW_ON_ERROR), "\n";
    try {
        $connection->query('DELETE FROM daily_sales');
        throw new LogicException('Read-only reporting allowed a write');
    } catch (DuckDB\Exception $error) {
        echo "read-only reporting rejected a write\n";
    }
} finally {
    if (isset($connection)) {
        $connection->close();
    }

    unset($connection, $database);
    // DuckDB may leave a WAL alongside the database after a failed operation.
    foreach ([$path . '.wal', $path] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($directory);
}
