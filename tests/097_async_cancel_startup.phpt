--TEST--
Immediate async cancellation survives native query startup and connection reuse
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
$sql = 'SELECT sum(i * j) FROM range(100000000) a(i), range(10000) b(j)';
$stmt = $conn->prepare($sql);

foreach (['query', 'prepared', 'multiple statements'] as $mode) {
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $pending = match ($mode) {
            'query' => $conn->queryAsync($sql),
            'prepared' => $stmt->executeAsync(),
            default => $conn->queryAsync('SELECT 1; ' . $sql),
        };
        $pending->cancel();
        $pending->cancel();
        try {
            $pending->await();
            throw new RuntimeException('Cancelled query unexpectedly completed');
        } catch (DuckDB\InterruptedException $error) {
            if ($error->getErrorType() !== DuckDB\ErrorType::Interrupt) {
                throw new RuntimeException('Expected an interruption error');
            }
        }
        unset($pending);
        if ($conn->queryAsync('SELECT 42 AS n')->await()->fetchColumn() !== 42) {
            throw new RuntimeException('Cancellation affected the next query');
        }
    }
    echo $mode, " cancelled and reused\n";
}

// Cancelling queued work must leave the earlier query on this connection intact.
$first = $conn->queryAsync('SELECT sum(i) FROM range(50000000) t(i)');
$queued = $conn->queryAsync($sql);
$queued->cancel();
var_dump($first->await()->fetchColumn());
try {
    $queued->await();
    throw new RuntimeException('Queued cancellation unexpectedly completed');
} catch (DuckDB\InterruptedException $error) {
    echo "queued cancellation preserved earlier query\n";
}
unset($first, $queued);

// A dropped handle still leaves its owned helper joined before shutdown.
for ($attempt = 0; $attempt < 4; $attempt++) {
    $dropped = $conn->queryAsync($sql);
    $dropped->cancel();
    unset($dropped);
    if ($conn->queryAsync('SELECT 42')->await()->fetchColumn() !== 42) {
        throw new RuntimeException('Dropping a cancelled handle affected reuse');
    }
}
echo "dropped cancelled handles cleaned up\n";

// Preserve duckdb_query's result selection for multiple statements.
$completed = $conn->queryAsync('SELECT 43 AS n; SELECT 1');
var_dump($completed->await()->fetchColumn());
$completed->cancel();
var_dump($conn->query('SELECT 44 AS n')->fetchColumn());
?>
--EXPECT--
query cancelled and reused
prepared cancelled and reused
multiple statements cancelled and reused
int(1249999975000000)
queued cancellation preserved earlier query
dropped cancelled handles cleaned up
int(43)
int(44)
