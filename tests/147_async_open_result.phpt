--TEST--
Async workers' statements are refused while a result holds the connection, and the open result is untouched
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();

function resource_in_use(Throwable $error, string $what): void
{
    check($error instanceof DuckDB\Exception && $error->getErrorType() === DuckDB\ErrorType::ResourceInUse,
        "$what: " . get_class($error) . ': ' . $error->getMessage());
}

// queryAsync() and executeAsync() started while a pending INSERT holds the
// connection fail when awaited; the INSERT then finishes and commits.
$conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
$conn->query('CREATE TABLE writes (i BIGINT)');
$counting = $conn->prepare('SELECT count(*) AS n FROM writes');
$old = $conn->queryPending('INSERT INTO writes SELECT i FROM range(1000000) t(i)');
check(!$old->isReady(), 'The INSERT is still running');
resource_in_use(failure(fn() => $conn->queryAsync('SELECT count(*) AS n FROM writes')->await()), 'queryAsync()');
resource_in_use(failure(fn() => $counting->executeAsync()->await()), 'executeAsync()');
check($old->await()->fetchAll() === [['Count' => 1000000]], 'The INSERT finished');
unset($old);
check($conn->queryAsync('SELECT count(*) AS n FROM writes')->await()->fetchAll() === [['n' => 1000000]],
    'The INSERT committed');
check($counting->executeAsync()->await()->fetchAll() === [['n' => 1000000]], 'executeAsync() runs again');
echo "async refused while a pending INSERT runs\n";

// A stream opened after queryAsync() but possibly before its worker starts
// always keeps its rows. The worker either ran first or is refused.
$outcomes = [];
for ($round = 0; $round < 20; $round++) {
    $async = $conn->queryAsync('SELECT 42 AS n');
    $stream = $conn->queryStreaming('SELECT i FROM range(200000) t(i)');
    $rows = 0;
    foreach ($stream as $row) {
        $rows++;
    }
    check($rows === 200000, "The stream returned $rows rows");
    try {
        check($async->await()->fetchAll() === [['n' => 42]], 'The async query completed');
        $outcomes['ran first'] = true;
    } catch (DuckDB\Exception $error) {
        resource_in_use($error, 'The async query');
        $outcomes['refused'] = true;
    }
    unset($stream, $async);
}
check($outcomes !== [], 'Every round produced an outcome');
echo "queryStreaming racing the async worker\n";

// An async worker's statement leaves a running pumped COPY alone: the COPY
// finishes and closes its writer.
$conn = (new DuckDB\Database(':memory:', ['threads' => 4]))->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(1000000)) TO '$dir/big.lines' (FORMAT lines)";
$pending = $conn->queryPending($copy);
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running');
}
resource_in_use(failure(fn() => $conn->queryAsync('SELECT 42 AS n')->await()), 'queryAsync()');
check($pending->await()->fetchAll() === [['Count' => 1000000]], 'The COPY wrote every row');
unset($pending);
check(!in_array('abort', $format->log, true), 'abort() did not run');
check(count(array_keys($format->log, 'close', true)) === 1, 'The COPY closed its writer once');
check(substr_count(file_get_contents("$dir/big.lines"), "\n") === 1000000, 'The file is complete');
check($conn->queryAsync('SELECT 42 AS n')->await()->fetchAll() === [['n' => 42]], 'queryAsync() runs again');
echo "pumped COPY keeps running\n";

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

// A writer whose destructor queries the same connection is released without
// deadlocking, whether its COPY is discarded or finishes after a refused
// async statement.
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
$querying_copy = "COPY (SELECT range AS i FROM range(1000000)) TO '$dir/q.out' (FORMAT querying)";
foreach (['discarded', 'finished'] as $mode) {
    $querying->log = [];
    $pending = $conn->queryPending($querying_copy);
    while (!in_array('write', $querying->log, true)) {
        check(!$pending->isReady(), 'The COPY is still running');
    }
    resource_in_use(failure(fn() => $conn->queryAsync('SELECT 43 AS n')->await()), 'queryAsync()');
    if ($mode === 'finished') {
        check($pending->await()->fetchAll() === [['Count' => 1000000]], 'The COPY wrote every row');
    }
    unset($pending);
    echo $mode, "\n";
    var_dump(array_values(array_filter($querying->log, fn($entry) => $entry !== 'write')));
    check($conn->query('SELECT 44 AS n')->fetchAll() === [['n' => 44]], 'The connection still works');
}
$querying->conn = null;
echo "querying destructor\n";
?>
--EXPECT--
async refused while a pending INSERT runs
queryStreaming racing the async worker
pumped COPY keeps running
queryAsync refused inside a COPY handler
discarded
array(2) {
  [0]=>
  string(5) "abort"
  [1]=>
  string(77) "destruct: Connection is busy executing a COPY handler; use another connection"
}
finished
array(2) {
  [0]=>
  string(5) "close"
  [1]=>
  string(77) "destruct: Connection is busy executing a COPY handler; use another connection"
}
querying destructor
