--TEST--
Hardening: NUL bytes in database path and config are rejected with ValueError
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
// A path with an embedded NUL would silently truncate at the C API
// boundary - it must be rejected instead.
try {
    new DuckDB\Database("duckdb_phpt_\0nul.db");
    echo "path: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "path: ValueError\n";
}

// Config option names and values must not contain NUL bytes either.
try {
    new DuckDB\Database(':memory:', ["threads\0" => 1]);
    echo "config key: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "config key: ValueError\n";
}
try {
    new DuckDB\Database(':memory:', ['threads' => "1\0garbage"]);
    echo "config value: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "config value: ValueError\n";
}

// A clean open still works afterwards.
var_dump((new DuckDB\Database())->connect()->query('SELECT 1 AS n')->fetchRow());
?>
--EXPECT--
path: ValueError
config key: ValueError
config value: ValueError
array(1) {
  ["n"]=>
  int(1)
}
