--TEST--
Version functions and extension metadata
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
var_dump(DuckDB\version());
var_dump(duckdb_version()); // legacy alias
var_dump(DuckDB\version() === duckdb_version());
var_dump(extension_loaded('duckdb'));
$classes = ['Database', 'Connection', 'Statement', 'Result', 'ResultIterator', 'PendingQuery', 'Appender', 'Interval', 'Exception'];
foreach ($classes as $c) {
    var_dump(class_exists("DuckDB\\$c"));
}
var_dump(enum_exists('DuckDB\FetchMode'));
var_dump(enum_exists('DuckDB\ErrorType'));
?>
--EXPECTF--
string(%d) "v%d.%d.%d%S"
string(%d) "v%d.%d.%d%S"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
