--TEST--
AMPHP v3: PendingQuery::suspend() suspends the fiber on the Revolt event loop
--VALGRIND-SKIP--
Concurrency/timing assertions are meaningless under Memcheck, and the test
requires AMPHP (a composer dependency), which is not part of what the
valgrind stage validates.
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
// AMPHP is userland: locate an autoloader that provides the Revolt event
// loop (amphp/amp v3 bundles revolt/event-loop).
$autoload = getenv('DUCKDB_AMPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    die('skip amphp not installed (set DUCKDB_AMPHP_AUTOLOAD, or composer require amphp/amp)');
}
require_once $autoload;
if (!class_exists('Revolt\\EventLoop')) {
    die('skip Revolt event loop not available');
}
?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\FetchMode;

$autoload = getenv('DUCKDB_AMPHP_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once $autoload;

const HEAVY = 'SELECT sum(t1.range) FROM range(20000000) t1, range(20) t2';
const HEAVY_RESULT = 3999999800000000;

$db = new Database(':memory:');
$db->connect()->query('PRAGMA threads=1'); // single-threaded: overlap is provable
// One connection per fiber: a connection serializes its queries by design.
$mk = fn() => $db->connect();

// --- Scheduler stays responsive while a worker-mode query is suspended ----
$ticks = 0;
Amp\async(function () use (&$ticks) {
    $until = microtime(true) + 1.2;
    while (microtime(true) < $until) {
        $ticks++;
        Amp\delay(0.01);
    }
});
$sum = Amp\async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0])->await();
echo $sum === HEAVY_RESULT ? "worker-result-ok\n" : "worker-result-BAD($sum)\n";
// The suspended fiber must not block the loop: a 10ms ticker accumulates
// dozens of ticks during a ~0.4s query.
echo $ticks >= 5 ? "loop-responsive\n" : "loop-BLOCKED($ticks)\n";

// --- Two heavy queries on two fibers overlap ------------------------------
$t0 = microtime(true);
$futures = [
    Amp\async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0]),
    Amp\async(fn() => $mk()->queryAsync(HEAVY)->suspend()->fetchRow(FetchMode::Num)[0]),
];
[$ra, $rb] = Amp\Future\await($futures);
$elapsed = microtime(true) - $t0;
echo ($ra === HEAVY_RESULT && $rb === HEAVY_RESULT) ? "overlap-results-ok\n" : "overlap-results-BAD\n";
// Sequential would cost ~2x a single query; allow generous headroom.
echo $elapsed < 3.0 ? "overlap-ok\n" : "overlap-BAD({$elapsed}s)\n";

// --- Polling mode: suspend() drives the query in slices between yields ----
$pollingSum = Amp\async(
    fn() => $mk()->queryPending('SELECT sum(t.range) FROM range(50000000) t')
        ->suspend()->fetchRow(FetchMode::Num)[0]
)->await();
echo $pollingSum === 1249999975000000 ? "polling-result-ok\n" : "polling-result-BAD($pollingSum)\n";

// --- Prepared statement ----------------------------------------------------
$stmtResult = Amp\async(function () use ($mk) {
    $stmt = $mk()->prepare('SELECT ?::INT + 1');
    return $stmt->executeAsync([41])->suspend()->fetchRow(FetchMode::Num)[0];
})->await();
echo $stmtResult === 42 ? "statement-ok\n" : "statement-BAD($stmtResult)\n";

// --- Query errors propagate into the fiber ---------------------------------
$error = Amp\async(function () use ($mk) {
    try {
        $mk()->queryAsync('SELECT * FROM missing_table')->suspend();
        return 'no-throw';
    } catch (DuckDB\CatalogException $e) {
        return 'catalog-ok';
    }
})->await();
echo $error, "\n";

// --- Top level: the main context suspends by running the loop -------------
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
toplevel-ok
