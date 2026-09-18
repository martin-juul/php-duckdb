--TEST--
Regression: fetchAll() with an exception mid-fetch must not corrupt return_value (UAF)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// fetchAll throws part-way (stream invalidated by a newer query): the
// half-built array must be destroyed AND the slot cleared - previously
// the dangling zval crashed the engine after the method returned.
$stream = $conn->queryStreaming('SELECT range AS v FROM range(10000)');
$stream->fetchRow();
$conn->query('SELECT 1'); // invalidates the stream

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
$stream2 = $conn->queryStreaming('SELECT range AS v FROM range(10000)');
$conn->query('SELECT 1');
try {
    foreach ($stream2 as $row) {
    }
    echo "iterator: no exception\n";
} catch (DuckDB\Exception $e) {
    echo "iterator: DuckDB\\Exception\n";
}
echo "done\n";
?>
--EXPECT--
fetchAll: DuckDB\Exception
vm alive: 100
iterator: DuckDB\Exception
done
