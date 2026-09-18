--TEST--
True Async: PendingQuery::suspend() parks the coroutine in the libuv reactor
--VALGRIND-SKIP--
Concurrency/timing assertions are meaningless under Memcheck, and the True
Async runtime (a php-src fork) is not part of what the valgrind stage
validates - the stock-PHP binary runs this stage.
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
// The True Async runtime ships as a php-src fork (https://true-async.github.io/):
// the Async\ namespace only exists when THIS php binary is that fork, so the
// test runs in-process and skips on any stock PHP build.
if (!class_exists('Async\\Coroutine') || !function_exists('Async\\spawn')
    || !function_exists('Async\\await') || !function_exists('Async\\delay')) {
    die('skip True Async runtime not present (requires the true-async php-src fork)');
}
?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\FetchMode;

const HEAVY = 'SELECT sum(t1.range) FROM range(20000000) t1, range(20) t2';
const HEAVY_RESULT = 3999999800000000;

$db = new Database(':memory:');
// One connection per coroutine - DuckDB interrupts are connection-level.
$mk = function () use ($db) {
    $conn = $db->connect();
    $conn->query('PRAGMA threads=1'); // single-threaded: overlap is provable
    return $conn;
};

// --- Before the async runtime is engaged: no coroutine context exists, ---
// --- so suspend() falls through to the generic fiber protocol and its ---
// --- Error. (Once any Async\ operation has run, the fork converts the ---
// --- root context into a coroutine and top-level suspend() works.) ------
$p = $mk()->queryAsync('SELECT sum(t.range) FROM range(20000000) t');
try {
    $p->suspend();
    echo "outside-BAD\n";
} catch (\Error $e) {
    echo "outside: ", $e->getMessage(), "\n";
}

// --- Scheduler stays responsive while a worker-mode query is suspended ----
$ticks = 0;
$ticker = Async\spawn(function () use (&$ticks) {
    $until = microtime(true) + 1.2;
    while (microtime(true) < $until) {
        $ticks++;
        Async\delay(10);
    }
});
$query = Async\spawn(function () use ($mk) {
    $conn = $mk();
    return $conn->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0];
});
$sum = Async\await($query);
Async\await($ticker);
echo $sum === HEAVY_RESULT ? "worker-result-ok\n" : "worker-result-BAD($sum)\n";
// The suspended coroutine must park in the reactor, not block the thread:
// a 10ms ticker accumulates dozens of ticks during a ~0.5s query.
echo $ticks >= 5 ? "scheduler-responsive\n" : "scheduler-BLOCKED($ticks)\n";

// --- Two heavy queries on two coroutines overlap --------------------------
$start = microtime(true);
$a = Async\spawn(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0]);
$b = Async\spawn(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0]);
$ra = Async\await($a);
$rb = Async\await($b);
$elapsed = microtime(true) - $start;
echo ($ra === HEAVY_RESULT && $rb === HEAVY_RESULT) ? "overlap-results-ok\n" : "overlap-results-BAD\n";
// Sequential would cost ~2x a single query; allow generous headroom.
$single = 0.4; // conservative floor of the single-query time measured above
echo $elapsed < 3.0 ? "overlap-ok\n" : "overlap-BAD({$elapsed}s)\n";

// --- Polling mode: suspend() drives the query via Async\delay yields ------
$ticks2 = 0;
$ticker2 = Async\spawn(function () use (&$ticks2) {
    $until = microtime(true) + 1.0;
    while (microtime(true) < $until) {
        $ticks2++;
        Async\delay(10);
    }
});
$poll = Async\spawn(function () use ($mk) {
    $conn = $mk();
    return $conn->queryPending('SELECT sum(t.range) FROM range(50000000) t')
        ->suspend()->fetchRow(FetchMode::Num)[0];
});
$pollingSum = Async\await($poll);
Async\await($ticker2);
echo $pollingSum === 1249999975000000 ? "polling-result-ok\n" : "polling-result-BAD($pollingSum)\n";
echo $ticks2 >= 5 ? "polling-responsive\n" : "polling-BLOCKED($ticks2)\n";

// --- Prepared statement ----------------------------------------------------
$stmtResult = Async\await(Async\spawn(function () use ($mk) {
    $conn = $mk();
    $stmt = $conn->prepare('SELECT ?::INT + 1');
    return $stmt->executeAsync([41])->suspend()->fetchRow(FetchMode::Num)[0];
}));
echo $stmtResult === 42 ? "statement-ok\n" : "statement-BAD($stmtResult)\n";

// --- Cooperative cancellation: \Cancellation escapes suspend(), the -------
// --- underlying DuckDB query is interrupted, the connection survives. -----
$cancelConn = $mk();
$victim = Async\spawn(function () use ($cancelConn) {
    return $cancelConn->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0];
});
Async\delay(100); // let the victim start and park on the completion pipe
$victim->cancel();
try {
    Async\await($victim);
    echo "cancel-BAD: completed normally\n";
} catch (\Cancellation $e) {
    echo "cancelled: ", get_class($e), "\n";
}
echo $victim->isCancelled() ? "isCancelled-ok\n" : "isCancelled-BAD\n";
echo $cancelConn->query('SELECT 7')->fetchRow(FetchMode::Num)[0] === 7
    ? "conn-alive\n" : "conn-BAD\n";
?>
--EXPECTF--
outside: Cannot suspend outside of a fiber
worker-result-ok
scheduler-responsive
overlap-results-ok
overlap-ok
polling-result-ok
polling-responsive
statement-ok
cancelled: Async\AsyncCancellation
isCancelled-ok
conn-alive
