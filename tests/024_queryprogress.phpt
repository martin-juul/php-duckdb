--TEST--
Connection: queryProgress and close guards
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// Idle connection: no progress information
$progress = $conn->queryProgress();
var_dump($progress['percentage']);
var_dump($progress['rowsProcessed']);
var_dump($progress['totalRowsToProcess']);

// Every method rejects a closed connection
$conn->close();
foreach (['query', 'queryStreaming', 'queryAsync', 'queryPending', 'prepare', 'appender', 'getTableNames', 'queryProgress'] as $method) {
    try {
        match ($method) {
            'appender' => $conn->appender('t'),
            'getTableNames' => $conn->getTableNames('SELECT 1'),
            'queryProgress' => $conn->queryProgress(),
            default => $conn->$method('SELECT 1'),
        };
        echo "$method: NO EXCEPTION\n";
    } catch (DuckDB\ConnectionException $e) {
        echo "$method: closed\n";
    }
}
try {
    $conn->interrupt();
    echo "interrupt: ok (no-op)\n";
} catch (DuckDB\ConnectionException $e) {
    echo "interrupt: closed\n";
}
?>
--EXPECT--
float(-1)
int(0)
int(0)
query: closed
queryStreaming: closed
queryAsync: closed
queryPending: closed
prepare: closed
appender: closed
getTableNames: closed
queryProgress: closed
interrupt: closed
