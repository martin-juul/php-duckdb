<?php

if (PHP_INT_SIZE !== 8 || !extension_loaded('duckdb')) {
    throw new RuntimeException('Requires 64-bit PHP with DuckDB loaded');
}

$connection = (new DuckDB\Database())->connect();
$row = $connection->query('SELECT 42 AS n')->fetchRow();
if ($row['n'] !== 42) {
    throw new RuntimeException('Query smoke test failed');
}

printf(
    "smoke OK: PHP %s, extension %s, DuckDB %s\n",
    PHP_VERSION,
    phpversion('duckdb'),
    DuckDB\version()
);
