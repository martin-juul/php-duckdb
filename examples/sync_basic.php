<?php
// Synchronous usage.

use DuckDB\Database;
use DuckDB\Exception;

require __DIR__ . '/bootstrap.php';

$db = new Database(':memory:');
$con = $db->connect();

$con->query('CREATE TABLE ducks (id INTEGER, name VARCHAR)');
$con->query("INSERT INTO ducks VALUES (1, 'Quackers'), (2, 'Waddles')");

$result = $con->query('SELECT * FROM ducks ORDER BY id');
printf("columns: %d, rows: %d\n", $result->columnCount(), $result->rowCount());
printf("col 0: %s (%s)\n", $result->columnName(0), $result->columnType(0));

while (($row = $result->fetchRow()) !== null) {
    printf("#%d %s\n", $row['id'], $row['name']);
}

try {
    $con->query('SELECT * FROM nonexistent_table');
} catch (Exception $e) {
    printf("caught DuckDB exception: %s\n", $e->getMessage());
}

printf("duckdb library version: %s\n", duckdb_version());
