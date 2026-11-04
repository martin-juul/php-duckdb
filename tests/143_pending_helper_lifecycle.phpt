--TEST--
PendingQuery: pumped pending COPY statements progress on the connection's helper thread
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--ENV--
DUCKDB_PHP_COPY_STUCK_SECONDS=5
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();
$db = new DuckDB\Database(':memory:', ['threads' => 4]);
$conn = $db->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);

$copy = fn(string $name, int $rows)
    => "COPY (SELECT range AS i FROM range($rows) ORDER BY i) TO '$dir/$name.lines' (FORMAT lines)";

/** Check a finished 5,000-row COPY: its count, every row in order, one writer. */
function check_copy(DuckDB\Result $result, LinesFormat $format, string $path, string $name): void
{
    check($result->fetchAll() === [['Count' => 5000]], "$name reports its row count");
    check(array_column(copy_lines($path), 0) === range(0, 4999), "$name writes every row in order");
    $counts = array_count_values($format->log);
    check($counts['open'] === 1 && $counts['close'] === 1, "$name opens and closes one writer");
    check(!in_array('abort', $format->log, true), "$name is not aborted");
}

// Every way of driving a pending query completes a COPY that reaches PHP
// from DuckDB's worker threads.
$drivers = [
    'isReady' => function (DuckDB\PendingQuery $pending): DuckDB\Result {
        while (!$pending->isReady()) {
        }

        return $pending->await();
    },
    'await' => fn(DuckDB\PendingQuery $pending): DuckDB\Result => $pending->await(),
    'suspend' => function (DuckDB\PendingQuery $pending): DuckDB\Result {
        $fiber = new Fiber(fn() => $pending->suspend());
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        return $fiber->getReturn();
    },
];
foreach ($drivers as $name => $drive) {
    for ($round = 0; $round < 3; $round++) {
        $format->reset();
        $result = $drive($conn->queryPending($copy($name, 5000)));
        check_copy($result, $format, "$dir/$name.lines", $name);
    }
    echo "$name\n";
}

// Between two isReady() calls, another connection of the same database runs
// statements, including a pumped COPY of its own.
$other = $db->connect();
$otherFormat = new LinesFormat();
$other->registerCopyToFunction('lines', $otherFormat);
$format->reset();
$pending = $conn->queryPending($copy('interleaved', 5000));
$pending->isReady();
check($other->query('SELECT 42 AS v')->fetchAll() === [['v' => 42]], 'The other connection runs a query');
$other->query("COPY (SELECT 7 AS v) TO '$dir/other.lines' (FORMAT lines)");
check(copy_lines("$dir/other.lines") === [[7]], 'The other connection runs a pumped COPY');
while (!$pending->isReady()) {
}
check_copy($pending->await(), $format, "$dir/interleaved.lines", 'interleaved');
unset($pending);
echo "other connection between steps\n";

// cancel() mid-execution stops the statement, and the connection keeps working.
$format->reset();
$pending = $conn->queryPending("COPY (SELECT range AS i FROM range(50000000)) TO '$dir/cancelled.lines' (FORMAT lines)");
while (!in_array('write', $format->log, true)) {
    check(!$pending->isReady(), 'The COPY is still running');
}
$pending->cancel();
check(failure(fn() => $pending->await()) instanceof DuckDB\InterruptedException, 'The cancelled COPY was interrupted');
check(in_array('abort', $format->log, true), 'The cancelled COPY aborted its writer');
unset($pending);
check($conn->query('SELECT 43 AS v')->fetchAll() === [['v' => 43]], 'The next query succeeds');
$format->reset();
check_copy($conn->queryPending($copy('after-cancel', 5000))->await(), $format, "$dir/after-cancel.lines", 'after-cancel');
echo "cancelled mid-execution\n";
?>
--EXPECT--
isReady
await
suspend
other connection between steps
cancelled mid-execution
