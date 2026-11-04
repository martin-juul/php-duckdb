--TEST--
Polling query cleanup releases partially executed work and the connection without interrupting newer queries
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$c = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
$sql = 'SELECT sum(i) AS n FROM range(50000000) t(i)';

// Run one slice before cancellation: destroying an unstarted handle does
// not exercise DuckDB's partially executed pipeline cleanup (also Memcheck).
$p = $c->queryPending($sql);
var_dump($p->isReady());
$p->cancel();
$p->cancel(); // idempotent
try {
    $p->await();
} catch (DuckDB\InterruptedException $e) {
    echo "cancelled\n";
}
unset($p);
var_dump($c->query('SELECT 42 AS n')->fetchRow()['n']);

// Dropping an unfinished handle must perform the same cleanup.
$p = $c->queryPending($sql);
var_dump($p->isReady());
unset($p);
var_dump($c->query('SELECT 43 AS n')->fetchRow()['n']);

// While an unfinished handle holds the connection, DuckDB refuses a newer
// query. Once the old handle is cancelled or dropped, a newer query runs, and
// destroying the old handle afterwards must not interrupt it.
foreach (['cancel', 'drop'] as $mode) {
    $old = $c->queryPending($sql);
    $old->isReady();
    try {
        $c->queryPending('SELECT 1');
    } catch (DuckDB\Exception $e) {
        echo $mode, ': ', $e->getErrorType()->name, "\n";
    }
    if ($mode === 'cancel') {
        $old->cancel();
    } else {
        unset($old);
    }
    $new = $c->queryPending('SELECT sum(i) AS n FROM range(1000000) t(i)');
    $new->isReady();
    unset($old);
    var_dump($new->await()->fetchRow()['n']);
}

// An async worker's statement is refused while an undriven INSERT holds the
// connection. Cancelling or dropping the INSERT interrupts it: it never
// finishes and commits.
$c->query('CREATE TABLE cleanup_writes (i BIGINT)');
foreach (['cancel', 'drop'] as $mode) {
    $old = $c->queryPending('INSERT INTO cleanup_writes SELECT i FROM range(1000000) t(i)');
    var_dump($old->isReady());
    try {
        $c->queryAsync('SELECT count(*) AS n FROM cleanup_writes')->await();
    } catch (DuckDB\Exception $e) {
        echo $mode, ' async: ', $e->getErrorType()->name, "\n";
    }
    if ($mode === 'cancel') {
        $old->cancel();
    }
    unset($old);
    var_dump($c->queryAsync('SELECT count(*) AS n FROM cleanup_writes')->await()->fetchRow()['n']);
}
?>
--EXPECT--
bool(false)
cancelled
int(42)
bool(false)
int(43)
cancel: ResourceInUse
int(499999500000)
drop: ResourceInUse
int(499999500000)
bool(false)
cancel async: ResourceInUse
int(0)
bool(false)
drop async: ResourceInUse
int(0)
