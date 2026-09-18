--TEST--
Result: large result sets verified row-by-row across vector chunk boundaries
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\FetchMode;

$conn = (new Database())->connect();

// 1M rows streamed with strict per-row verification. DuckDB ships results in
// 2048-row vectors; any chunk-boundary off-by-one or corruption breaks this
// sequence immediately.
$result = $conn->queryStreaming('SELECT i::BIGINT AS i, (i * 2)::BIGINT AS d FROM range(1000000) t(i)');
$expected = 0;
$sum = 0;
foreach ($result as $row) {
    if ($row['i'] !== $expected || $row['d'] !== $expected * 2) {
        echo "MISMATCH at row $expected\n";
        exit(1);
    }
    $sum += $row['i'];
    $expected++;
}
echo "stream: count=$expected sum=$sum\n";

// Exhausted streams stay exhausted
var_dump($result->fetchRow());

// Buffered fetchAll of 100k rows
$rows = $conn->query('SELECT i::BIGINT AS i FROM range(100000) t(i)')->fetchAll(FetchMode::Num);
$ordered = $rows[0][0] === 0 && $rows[99999][0] === 99999;
$s = 0;
foreach ($rows as $r) {
    $s += $r[0];
}
echo 'buffered: count=', count($rows), " sum=$s ", $ordered ? 'ordered' : 'BROKEN', "\n";

// fetchColumn over a large result
$result = $conn->query('SELECT i::BIGINT AS i FROM range(10000) t(i)');
$count = 0;
$last = -1;
while (($v = $result->fetchColumn()) !== null) {
    $last = $v;
    $count++;
}
echo "fetchColumn: count=$count last=$last\n";
echo "done\n";
?>
--EXPECT--
stream: count=1000000 sum=499999500000
NULL
buffered: count=100000 sum=4999950000 ordered
fetchColumn: count=10000 last=9999
done
