--TEST--
Async workers supersede open results without running PHP off the request thread
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();

// A pending INSERT superseded by queryAsync() rolls back, and the async
// query runs on the connection the INSERT held.
$conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
$conn->query('CREATE TABLE writes (i BIGINT)');
$old = $conn->queryPending('INSERT INTO writes SELECT i FROM range(1000000) t(i)');
check(!$old->isReady(), 'The INSERT is still running');
$new = $conn->queryAsync('SELECT count(*) AS n FROM writes');
check($new->await()->fetchAll() === [['n' => 0]], 'The async query saw no rows');
$error = failure(fn() => $old->await());
check($error instanceof DuckDB\Exception, get_class($error));
check(str_contains($error->getMessage(), 'superseded by another operation'), $error->getMessage());
unset($old);
check($conn->query('SELECT count(*) AS n FROM writes')->fetchAll() === [['n' => 0]], 'The INSERT rolled back');
echo "pending INSERT superseded by queryAsync\n";

// A stream opened after queryAsync() but possibly before its worker starts
// either keeps its rows or reports that the worker superseded it.
$outcomes = [];
for ($round = 0; $round < 20; $round++) {
    $async = $conn->queryAsync('SELECT 42 AS n');
    $stream = $conn->queryStreaming('SELECT i FROM range(200000) t(i)');
    $rows = 0;
    try {
        foreach ($stream as $row) {
            $rows++;
        }
        check($rows === 200000, "The stream returned $rows rows");
        $outcomes['rows'] = true;
    } catch (DuckDB\Exception $error) {
        check(str_contains($error->getMessage(), 'invalidated by a newer query'), $error->getMessage());
        check($stream->columns() === [['name' => 'i', 'type' => 'BIGINT']], 'Metadata stays readable');
        $outcomes['superseded'] = true;
    }
    check($async->await()->fetchAll() === [['n' => 42]], 'The async query completed');
    unset($stream, $async);
}
check($outcomes !== [], 'Every round produced an outcome');
echo "queryStreaming racing the async worker\n";

// A superseded pumped COPY aborts its writer exactly once, on the request
// thread, when an async worker superseded it.
$conn = (new DuckDB\Database(':memory:', ['threads' => 4]))->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(5000000)) TO '$dir/big.lines' (FORMAT lines)";
$pending = $conn->queryPending($copy);
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running');
}
$async = $conn->queryAsync('SELECT 42 AS n');
check($async->await()->fetchAll() === [['n' => 42]], 'The async query completed');
$aborts = fn() => count(array_keys($format->log, 'abort', true));
check($aborts() === 1, 'abort() ran once the async query returned');
check(str_contains($format->reasons[0]->getMessage(), 'superseded by another operation'),
    $format->reasons[0]->getMessage());
$error = failure(fn() => $pending->await());
check(str_contains($error->getMessage(), 'superseded by another operation'), $error->getMessage());
unset($pending, $async);
check($aborts() === 1, 'abort() ran exactly once');
check(!in_array('close', $format->log, true), 'The superseded COPY did not close its writer');
echo "pumped COPY aborted once\n";

// queryAsync() from inside a COPY handler on its own connection is refused
// like every other statement start.
$format->reset();
$refused = [];
$format->hooks['write'] = function () use ($conn, &$refused) {
    if ($refused !== []) {
        return;
    }
    $refused[] = failure(fn() => $conn->queryAsync('SELECT 1'));
};
$conn->query("COPY (SELECT 1 AS i) TO '$dir/handler.lines' (FORMAT lines)");
$format->hooks = [];
check(count($refused) === 1, 'The handler tried queryAsync()');
check($refused[0] instanceof DuckDB\ConnectionException, get_class($refused[0]));
check(str_contains($refused[0]->getMessage(), 'Connection is busy executing a COPY handler'),
    $refused[0]->getMessage());
echo "queryAsync refused inside a COPY handler\n";

// A writer whose destructor queries the same connection is released after
// an async supersede without deadlocking on the connection.
final class QueryingWriter implements DuckDB\CopyToWriter
{
    public function __construct(private QueryingFormat $format)
    {
    }

    public function write(DuckDB\DataChunk $batch): void
    {
        $this->format->log[] = 'write';
    }

    public function close(): void
    {
        $this->format->log[] = 'close';
    }

    public function abort(Throwable $reason): void
    {
        $this->format->log[] = 'abort';
    }

    public function __destruct()
    {
        try {
            $this->format->conn->query('SELECT 1');
            $this->format->log[] = 'destruct: query ran';
        } catch (DuckDB\ConnectionException $error) {
            $this->format->log[] = 'destruct: ' . $error->getMessage();
        }
    }
}

final class QueryingFormat implements DuckDB\CopyToFunction
{
    public array $log = [];
    public ?DuckDB\Connection $conn = null;

    public function bind(array $columnTypes, array $options): void
    {
    }

    public function open(string $path, array $columnTypes, array $options): DuckDB\CopyToWriter
    {
        return new QueryingWriter($this);
    }
}

$querying = new QueryingFormat();
$querying->conn = $conn;
$conn->registerCopyToFunction('querying', $querying);
$pending = $conn->queryPending("COPY (SELECT range AS i FROM range(5000000)) TO '$dir/q.out' (FORMAT querying)");
while (!in_array('write', $querying->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running');
}
$async = $conn->queryAsync('SELECT 43 AS n');
check($async->await()->fetchAll() === [['n' => 43]], 'The async query completed');
$log = array_values(array_filter($querying->log, fn($entry) => $entry !== 'write'));
var_dump($log);
check(failure(fn() => $pending->await()) instanceof DuckDB\Exception, 'The superseded COPY failed');
unset($pending, $async);
check($conn->query('SELECT 44 AS n')->fetchAll() === [['n' => 44]], 'The connection still works');
$querying->conn = null;
echo "querying destructor\n";
?>
--EXPECT--
pending INSERT superseded by queryAsync
queryStreaming racing the async worker
pumped COPY aborted once
queryAsync refused inside a COPY handler
array(2) {
  [0]=>
  string(5) "abort"
  [1]=>
  string(77) "destruct: Connection is busy executing a COPY handler; use another connection"
}
querying destructor
