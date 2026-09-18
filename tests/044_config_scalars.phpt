--TEST--
Config: scalar values (incl. booleans) are mapped to DuckDB config strings correctly
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
// booleans map to DuckDB's canonical "true"/"false" (zval stringification
// would produce "1"/"" and be rejected)
$db = new DuckDB\Database(':memory:', ['allow_unsigned_extensions' => true]);
echo "bool true ok\n";
$db = new DuckDB\Database(':memory:', ['allow_unsigned_extensions' => false]);
echo "bool false ok\n";
$db = new DuckDB\Database(':memory:', ['threads' => 2, 'memory_limit' => '256MB']);
echo "int + string ok\n";

// unknown options are rejected
try {
    new DuckDB\Database(':memory:', ['nonsense_option_xyz' => 'x']);
    echo "unknown option: NOT REJECTED\n";
} catch (DuckDB\Exception $e) {
    echo "unknown option: DuckDB\\Exception\n";
}

// non-scalar values and integer keys are programmer errors
try {
    new DuckDB\Database(':memory:', ['threads' => [1]]);
} catch (\ValueError $e) {
    echo "array value: ValueError\n";
}
try {
    new DuckDB\Database(':memory:', [0 => 'x']);
} catch (\ValueError $e) {
    echo "int key: ValueError\n";
}
echo "done\n";
?>
--EXPECT--
bool true ok
bool false ok
int + string ok
unknown option: DuckDB\Exception
array value: ValueError
int key: ValueError
done
