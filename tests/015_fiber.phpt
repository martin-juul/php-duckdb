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
$deadline = hrtime(true) + 10_000_000_000;
while (!$fiber->isTerminated()) {
    if (hrtime(true) >= $deadline) {
        echo "fiber never finished\n";
        exit(1);
    }
    // Give the worker CPU time; a resume-count limit depends on scheduling.
    usleep(1000);
    $fiber->resume();
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
    // Drive a multi-morsel aggregate on this thread. Unlike queryAsync(),
    // polling execution cannot finish while PHP is descheduled before
    // suspend(). One execution slice still leaves work to suspend for.
    $polling = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
    $pending = $polling->queryPending('SELECT sum(i) FROM range(50000000) t(i)');
    try {
        $pending->suspend();
        echo "outside fiber: BAD - no error\n";
    } catch (FiberError $e) {
        echo 'outside fiber: ', $e->getMessage(), "\n";
    }
    // Release the unfinished polling query.
    $pending->cancel();
}
?>
--EXPECTF--
array(1) {
  ["n"]=>
  int(123)
}
%routside fiber: (Cannot suspend outside of a fiber|async root coroutine, n=1)%r
