--TEST--
PendingQuery: Fiber integration (suspend)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$fiber = new Fiber(function () use ($conn) {
    $pending = $conn->queryAsync('SELECT 123 AS n');
    return $pending->suspend();
});
$fiber->start();
$loops = 0;
while (!$fiber->isTerminated()) {
    $fiber->resume();
    if (++$loops > 100000) {
        echo "fiber never finished\n";
        exit(1);
    }
}
var_dump($fiber->getReturn()->fetchRow());

// suspend() outside a fiber throws - unless the True Async runtime has
// engaged, because its php-src fork then treats the root context as a
// coroutine and top-level suspension becomes legal (and works).
$asyncRoot = false;
if (function_exists('Async\\current_coroutine')) {
    try {
        Async\current_coroutine();
        $asyncRoot = true;
    } catch (Throwable) {
    }
}
if ($asyncRoot) {
    $r = $conn->queryAsync('SELECT 1 AS n')->suspend();
    echo 'outside fiber: async root coroutine, n=', $r->fetchRow()['n'], "\n";
} else {
    // Heavy enough that the worker cannot finish before the first
    // duckdb_task_step, so Fiber::suspend is reliably reached and throws
    // (a trivial query can complete first, skip the loop and never throw).
    $pending = $conn->queryAsync('SELECT count(*) FROM range(1000000) t1, range(100) t2');
    try {
        $pending->suspend();
        echo "outside fiber: BAD - no error\n";
    } catch (FiberError $e) {
        echo 'outside fiber: ', $e->getMessage(), "\n";
    }
    // Interrupt the abandoned worker so request shutdown does not race it.
    $pending->cancel();
}
?>
--EXPECTF--
array(1) {
  ["n"]=>
  int(123)
}
%routside fiber: (Cannot suspend outside of a fiber|async root coroutine, n=1)%r
