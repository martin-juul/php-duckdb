--TEST--
ReactPHP (react/async v4+): PendingQuery::suspend() awaits on the event loop
--VALGRIND-SKIP--
Concurrency/timing assertions are meaningless under Memcheck, and the test
requires ReactPHP (a composer dependency), which is not part of what the
valgrind stage validates.
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
// ReactPHP is userland: locate an autoloader that provides react/async v4+
// (v3 is deliberately not engaged - SimpleFiber exists only in v4).
$autoload = getenv('DUCKDB_REACTPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    die('skip reactphp not installed (set DUCKDB_REACTPHP_AUTOLOAD, or composer require react/async)');
}
require_once $autoload;
if (!class_exists('React\\Async\\SimpleFiber')) {
    die('skip react/async >= 4.0 required');
}
?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\FetchMode;
use React\EventLoop\Loop;
use function React\Async\async;
use function React\Async\await;

$autoload = getenv('DUCKDB_REACTPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once $autoload;

const HEAVY = 'SELECT sum(t1.range) FROM range(20000000) t1, range(20) t2';
const HEAVY_RESULT = 3999999800000000;

$db = new Database(':memory:');
$db->connect()->query('PRAGMA threads=1'); // single-threaded: overlap is provable
// One connection per fiber: a connection serializes its queries by design.
$mk = fn() => $db->connect();

// --- Loop stays responsive while a worker-mode query is suspended ---------
$ticks = 0;
$ticker = Loop::addPeriodicTimer(0.01, function () use (&$ticks) {
    $ticks++;
});
$sum = await(async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0])());
Loop::cancelTimer($ticker);
echo $sum === HEAVY_RESULT ? "worker-result-ok\n" : "worker-result-BAD($sum)\n";
// The suspended fiber must not block the loop: a 10ms ticker accumulates
// dozens of ticks during a ~0.4s query.
echo $ticks >= 5 ? "loop-responsive\n" : "loop-BLOCKED($ticks)\n";

// --- Two heavy queries on two fibers overlap ------------------------------
$t0 = microtime(true);
$pa = async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0])();
$pb = async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0])();
[$ra, $rb] = [await($pa), await($pb)];
$elapsed = microtime(true) - $t0;
echo ($ra === HEAVY_RESULT && $rb === HEAVY_RESULT) ? "overlap-results-ok\n" : "overlap-results-BAD\n";
// Sequential would cost ~2x a single query; allow generous headroom.
echo $elapsed < 3.0 ? "overlap-ok\n" : "overlap-BAD({$elapsed}s)\n";

// --- Polling mode: suspend() drives the query in slices between yields ----
$pollingSum = await(async(
    fn() => $mk()->queryPending('SELECT sum(t.range) FROM range(50000000) t')
        ->suspend()->fetchRow(FetchMode::Num)[0]
)());
echo $pollingSum === 1249999975000000 ? "polling-result-ok\n" : "polling-result-BAD($pollingSum)\n";

// --- Prepared statement ----------------------------------------------------
$stmtResult = await(async(function () use ($mk) {
    $stmt = $mk()->prepare('SELECT ?::INT + 1');
    return $stmt->executeAsync([41])->suspend()->fetchRow(FetchMode::Num)[0];
})());
echo $stmtResult === 42 ? "statement-ok\n" : "statement-BAD($stmtResult)\n";

// --- Query errors propagate into the fiber ---------------------------------
$error = await(async(function () use ($mk) {
    try {
        $mk()->queryAsync('SELECT * FROM missing_table')->suspend();
        return 'no-throw';
    } catch (DuckDB\CatalogException $e) {
        return 'catalog-ok';
    }
})());
echo $error, "\n";

// --- Cooperative cancellation: cancelling the async() promise rejects the --
// --- awaited promise; suspend() honours it by interrupting the query. -----
$cancelConn = $mk();
$promise = async(fn() => $cancelConn->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0])();
Loop::addTimer(0.1, function () use ($promise) {
    $promise->cancel();
});
try {
    await($promise);
    echo "cancel-BAD: completed normally\n";
} catch (\RuntimeException $e) {
    echo "cancelled: ", $e->getMessage(), "\n";
}
echo $cancelConn->query('SELECT 7')->fetchRow(FetchMode::Num)[0] === 7
    ? "conn-alive\n" : "conn-BAD\n";

// --- Top level: react/async's scheduler fiber runs the loop ---------------
$top = $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0];
echo $top === HEAVY_RESULT ? "toplevel-ok\n" : "toplevel-BAD($top)\n";
?>
--EXPECTF--
worker-result-ok
loop-responsive
overlap-results-ok
overlap-ok
polling-result-ok
statement-ok
catalog-ok
cancelled: Operation cancelled
conn-alive
toplevel-ok
