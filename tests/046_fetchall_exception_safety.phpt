--TEST--
Regression: fetchAll() with an exception mid-fetch must not corrupt return_value (UAF)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// fetchAll throws part-way (the query fails after its first chunks): the
// half-built array must be destroyed AND the slot cleared - previously
// the dangling zval crashed the engine after the method returned.
// A small streaming buffer keeps DuckDB from reaching the failing row
// before the first chunks are fetched.
$conn->query("SET streaming_buffer_size = '16KB'");
$failing = "SELECT CASE WHEN range < 50000 THEN range ELSE error('boom ' || range) END AS v FROM range(100000)";
$stream = $conn->queryStreaming($failing);
$stream->fetchRow();

try {
    $stream->fetchAll();
    echo "fetchAll: no exception\n";
} catch (DuckDB\Exception $e) {
    echo "fetchAll: DuckDB\\Exception\n";
}

// the PHP VM must still be healthy afterwards (this crashed pre-fix)
$arr = array_fill(0, 100, 'x');
echo "vm alive: ", count($arr), "\n";

// same for the iterator path
unset($stream);
$stream2 = $conn->queryStreaming($failing);
$rows = 0;
try {
    foreach ($stream2 as $row) {
        $rows++;
    }
    echo "iterator: no exception\n";
} catch (DuckDB\Exception $e) {
    echo "iterator: DuckDB\\Exception after ", $rows > 0 ? 'some' : 'no', " rows\n";
}
echo "done\n";
?>
--EXPECT--
fetchAll: DuckDB\Exception
vm alive: 100
iterator: DuckDB\Exception after some rows
done
