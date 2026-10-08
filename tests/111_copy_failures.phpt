--TEST--
COPY TO: handler failures, busy connections, registration rules and rejected execution paths
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();
$path = tempnam(sys_get_temp_dir(), 'duckdb-copy-db');
unlink($path);
register_shutdown_function(fn() => @unlink($path));
$db = new DuckDB\Database($path);
$conn = $db->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(5000)) TO '$dir/out.lines' (FORMAT lines)";

// AE2: a handler exception fails the COPY, is chained, and aborts the writer.
foreach (['open', 'write', 'close'] as $method) {
    $format->reset();
    $boom = new LogicException("$method failed");
    $format->hooks[$method] = function () use ($boom) {
        throw $boom;
    };
    $error = failure(fn() => $conn->query($copy));
    $format->hooks = [];
    check(get_class($error) === DuckDB\Exception::class, "$method: " . get_class($error));
    check(str_contains($error->getMessage(), "COPY format 'lines' $method(): $method failed"), $error->getMessage());
    check($error->getPrevious() === $boom, "$method: the handler exception is chained");
    $aborted = $method === 'open' ? [] : [$boom];
    check($format->reasons === $aborted, "$method: abort() receives the handler exception");
    check($conn->query('SELECT 1 AS ok')->fetchAll() === [['ok' => 1]], "$method: the connection is reusable");
    echo "$method failure\n";
}

$format->reset();
$format->hooks['bind'] = function () {
    throw new InvalidArgumentException('unsupported column');
};
$error = failure(fn() => $conn->query($copy));
$format->hooks = [];
check($error instanceof DuckDB\BinderException, 'bind: ' . get_class($error));
check(str_contains($error->getMessage(), "COPY format 'lines' bind(): unsupported column"), $error->getMessage());
check($error->getPrevious() instanceof InvalidArgumentException, 'bind: chained');
check($format->log === ['bind'], 'bind: nothing opened');
echo "bind failure\n";

// open() must return a writer.
$broken = new class implements DuckDB\CopyToFunction {
    public function bind(array $columnTypes, array $options): void
    {
    }

    public function open(string $path, array $columnTypes, array $options): DuckDB\CopyToWriter
    {
        throw new DomainException('cannot open ' . basename($path));
    }
};
$conn->registerCopyToFunction('broken', $broken);
$error = failure(fn() => $conn->query("COPY (SELECT 1) TO '$dir/broken.out' (FORMAT broken)"));
check(str_contains($error->getMessage(), "open(): cannot open broken.out"), $error->getMessage());
echo "open failure\n";

// AE3: the connection is busy inside a handler; other connections are not.
$other = $db->connect();
$seen = [];
$format->reset();
$format->hooks['write'] = function () use ($conn, $other, &$seen, $dir) {
    if ($seen !== []) {
        return;
    }
    $busy = failure(fn() => $conn->query('SELECT 1'));
    $seen[] = get_class($busy) . ': ' . $busy->getMessage();
    $seen[] = $other->query('SELECT 42 AS v')->fetchAll()[0]['v'];
    $nested = failure(fn() => $conn->prepare('SELECT 1'));
    $seen[] = get_class($nested);
    $helper = new LinesFormat();
    $inner = failure(fn() => $other->registerCopyToFunction('inner', $helper));
    $seen[] = $inner->getMessage();
};
$conn->query($copy);
$format->hooks = [];
check($seen[0] === 'DuckDB\ConnectionException: Connection is busy executing a COPY handler; use another connection',
    (string) $seen[0]);
check($seen[1] === 42 && $seen[2] === DuckDB\ConnectionException::class, json_encode($seen));
check($seen[3] === 'COPY formats cannot be registered inside a COPY handler', (string) $seen[3]);
echo "busy connection\n";

// A PHP format cannot run inside another handler, even on another connection.
$nestedFormat = new LinesFormat();
$other->registerCopyToFunction('nested', $nestedFormat);
$format->reset();
$nestedError = null;
$format->hooks['write'] = function () use ($other, $dir, &$nestedError) {
    $nestedError ??= failure(fn() => $other->query("COPY (SELECT 1) TO '$dir/nested.out' (FORMAT nested)"));
};
$conn->query($copy);
$format->hooks = [];
check(str_contains($nestedError->getMessage(), 'PHP COPY formats cannot run inside another COPY handler'),
    $nestedError->getMessage());
check($nestedFormat->log === [], 'The nested format never ran');
echo "nested format\n";

// Destroying a Result of the busy connection inside a handler is deferred.
$streaming = $conn->queryStreaming('SELECT range FROM range(100000)');
$format->reset();
$format->hooks['open'] = function () use (&$streaming) {
    $streaming = null;
};
$conn->query($copy);
$format->hooks = [];
check($streaming === null && $conn->query('SELECT 2 AS two')->fetchAll() === [['two' => 2]], 'Deferred release');
echo "deferred release\n";

// Registration rules.
foreach (['csv', 'Parquet', 'JSON'] as $name) {
    $error = failure(fn() => $conn->registerCopyToFunction($name, new LinesFormat()));
    check($error instanceof ValueError, "$name is rejected");
}
foreach (['', '1abc', 'with space', 'semi;colon'] as $name) {
    $error = failure(fn() => $conn->registerCopyToFunction($name, new LinesFormat()));
    check($error instanceof ValueError, "'$name' is rejected");
}
echo "names validated\n";

// Re-registering replaces the handler; a statement prepared before fails.
$first = new LinesFormat();
$second = new LinesFormat();
$conn->registerCopyToFunction('swap', $first);
$prepared = $conn->prepare("COPY (SELECT 1) TO '$dir/swap.out' (FORMAT swap)");
$conn->registerCopyToFunction('SWAP', $second);
$error = failure(fn() => $prepared->execute());
check(str_contains($error->getMessage(), 'prepare it again'), $error->getMessage());
$conn->prepare("COPY (SELECT 1) TO '$dir/swap.out' (FORMAT swap)")->execute();
check(in_array('close', $second->log, true) && !in_array('open', $first->log, true), 'The new handler runs');
echo "re-registration\n";

// AE4: worker-thread execution paths are rejected without running PHP.
$format->reset();
$error = failure(fn() => $conn->queryAsync($copy)->await());
check(str_contains($error->getMessage(), 'not queryAsync() or executeAsync()'), $error->getMessage());
$statement = $conn->prepare($copy);
$format->reset();
$error = failure(fn() => $statement->executeAsync());
check(str_contains($error->getMessage(), 'not queryAsync() or executeAsync()'), $error->getMessage());
check($format->log === [], 'No handler ran: ' . json_encode($format->log));
echo "async rejected\n";

// AE6: another connection to the same database cannot use the format.
$error = failure(fn() => $other->query("COPY (SELECT 1 AS i) TO '$dir/missing.out' (FORMAT lines)"));
check($error instanceof DuckDB\CatalogException, get_class($error) . ': ' . $error->getMessage());
$other->query("COPY (SELECT 1 AS i) TO '$dir/fallback.lines'");
check(file_get_contents("$dir/fallback.lines") === "i\n1\n", 'The extension falls back to CSV');
echo "connection scoped\n";

// AE5: parallel and multi-file COPY are rejected at bind.
$conn->query('SET threads = 4');
$format->reset();
$error = failure(fn() => $conn->query(
    "COPY (SELECT range % 2 AS p, range AS i FROM range(10)) TO '$dir/parts' (FORMAT lines, PARTITION_BY (p))"
));
check($error instanceof DuckDB\BinderException && str_contains($error->getMessage(), 'PARTITION_BY is not supported'),
    $error->getMessage());
check(!in_array('open', $format->log, true), 'Nothing opened');
$conn->query('CREATE TABLE t AS SELECT 1 AS i');
$error = failure(fn() => $conn->query("EXPORT DATABASE '$dir/export' (FORMAT lines)"));
check($error instanceof DuckDB\BinderException && str_contains($error->getMessage(), 'EXPORT DATABASE is not supported'),
    $error->getMessage());
$error = failure(fn() => $conn->query("COPY (SELECT 1) TO '$dir/threads' (FORMAT lines, PER_THREAD_OUTPUT true)"));
check($error instanceof DuckDB\BinderException, $error->getMessage());
echo "parallel rejected\n";
?>
--EXPECT--
open failure
write failure
close failure
bind failure
open failure
busy connection
nested format
deferred release
names validated
re-registration
async rejected
connection scoped
parallel rejected
