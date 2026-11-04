--TEST--
PendingQuery: a Fiber suspended on a running pumped COPY and never resumed is destroyed at shutdown without hanging
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
// True Async runs every Fiber as a coroutine. There, suspend() parks on the
// True Async scheduler until the query finishes and never hands control back
// to the code that resumes the Fiber, so there is no suspended Fiber to
// abandon. The extension makes the same check from inside the Fiber;
// tests/050_true_async.phpt covers that runtime.
if (function_exists('Async\current_coroutine')) {
    $probe = new Fiber(function (): bool {
        try {
            Async\current_coroutine();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    });
    $probe->start();
    if ($probe->getReturn()) {
        die('skip suspend() inside a Fiber runs on the True Async scheduler, not the Fiber fallback');
    }
}
?>
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

// The Fiber is never resumed after the COPY's first write: request shutdown
// destroys it and the statement without hanging.
$abandoned = new Fiber(
    fn() => $conn->queryPending("COPY (SELECT range AS i FROM range(50000000)) TO '$dir/abandoned.lines' (FORMAT lines)")->suspend()
);
$abandoned->start();
while (!in_array('write', $format->log, true)) {
    check(!$abandoned->isTerminated(), 'The abandoned COPY is still running');
    $abandoned->resume();
}
echo "abandoned fiber\n";
?>
--EXPECT--
abandoned fiber
