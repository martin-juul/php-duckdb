--TEST--
Database: configuration options
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{Database, ErrorType, Exception};

// Options are passed through to DuckDB
$db = new Database(':memory:', ['threads' => 2, 'memory_limit' => '256MB']);
$row = $db->connect()->query("SELECT current_setting('threads')::INT AS t")->fetchRow();
var_dump($row);

// Unknown options are rejected with the INVALID_CONFIGURATION category
try {
    new Database(':memory:', ['nonsense_option_xyz' => '1']);
    echo "no exception\n";
} catch (Exception $e) {
    echo 'code=', $e->getCode(), ' type=', $e->getErrorType()->name, "\n";
}

// Non-scalar config values are a ValueError
try {
    new Database(':memory:', ['threads' => [1]]);
} catch (ValueError $e) {
    echo 'ValueError caught', "\n";
}

// Unwritable paths fail with ConnectionException
try {
    new Database('/nonexistent-dir-xyz/db.duckdb');
    echo "no exception\n";
} catch (DuckDB\ConnectionException $e) {
    echo 'ConnectionException caught', "\n";
}
?>
--EXPECT--
array(1) {
  ["t"]=>
  int(2)
}
code=42 type=InvalidConfiguration
ValueError caught
ConnectionException caught
