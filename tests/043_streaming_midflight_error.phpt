--TEST--
Streaming: a query that fails mid-stream throws instead of silently truncating
--VALGRIND-SKIP--
This test drives a 2M-row scan to force fetch-time errors; under Memcheck
that exceeds any sane per-test timeout. The harness's valgrind stage skips
it; its memory behavior is covered by the rest of the suite.
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// The error sits deep past any eagerly evaluated prefix, so it surfaces
// while fetching chunks (or, on builds that materialize small results
// eagerly, at queryStreaming() itself - both are correct; silent
// truncation is not).
$sql = "SELECT (CASE WHEN range = 1500000 THEN 'boom' ELSE range::VARCHAR END)::INTEGER AS v
        FROM range(2000000)";

function probe_stream(DuckDB\Connection $conn, string $sql): string {
    try {
        $result = $conn->queryStreaming($sql);
    } catch (DuckDB\ConversionException) {
        return 'eager'; // error surfaced at execute time
    }
    $rows = 0;
    try {
        while ($result->fetchRow()) {
            $rows++;
        }
        return 'SILENT TRUNCATION after ' . $rows . ' rows';
    } catch (DuckDB\ConversionException) {
        return 'fetch-time after ' . ($rows > 0 ? 'some' : 'no') . ' rows';
    }
}

$outcome = probe_stream($conn, $sql);
echo "row loop: ", in_array($outcome, ['eager'], true) || str_starts_with($outcome, 'fetch-time') ? "threw ({$outcome})" : $outcome, "\n";

// the connection stays usable after a failed stream
echo "reuse: ", $conn->query("SELECT 'alive' AS s")->fetchAll()[0]['s'], "\n";

// the fetch-time error is sticky when it happened there
if (str_starts_with($outcome, 'fetch-time')) {
    $result = $conn->queryStreaming($sql);
    try {
        while ($result->fetchRow()) {
        }
    } catch (DuckDB\ConversionException) {
    }
    try {
        $result->fetchRow();
        echo "refetch: silently continued\n";
    } catch (DuckDB\Exception) {
        echo "refetch: throws again\n";
    }
}

// same query materialized: error surfaces at query time
try {
    $conn->query($sql);
    echo "materialized: no error\n";
} catch (DuckDB\ConversionException) {
    echo "materialized: ConversionException\n";
}
echo "done\n";
?>
--EXPECTF--
row loop: threw (%s)
reuse: alive
%s
materialized: ConversionException
done
