--TEST--
Polling query cleanup releases partially executed work without interrupting newer queries
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

// An old handle must not interrupt the query that replaced it.
foreach (['cancel', 'drop'] as $mode) {
    $old = $c->queryPending($sql);
    $old->isReady();
    $new = $c->queryPending('SELECT sum(i) AS n FROM range(1000000) t(i)');
    if ($mode === 'cancel') {
        $old->cancel();
    }
    unset($old);
    var_dump($new->await()->fetchRow()['n']);
}

// Queuing an async worker invalidates the PHP execution epoch before that
// worker necessarily acquires the connection. Cleanup must still interrupt
// the old INSERT if it owns the active execution, never finish and commit it.
$c->query('CREATE TABLE cleanup_writes (i BIGINT)');
foreach (['cancel', 'drop'] as $mode) {
    $old = $c->queryPending('INSERT INTO cleanup_writes SELECT i FROM range(1000000) t(i)');
    var_dump($old->isReady());
    $new = $c->queryAsync('SELECT count(*) AS n FROM cleanup_writes');
    if ($mode === 'cancel') {
        $old->cancel();
    }
    unset($old);
    var_dump($new->await()->fetchRow()['n']);
}
?>
--EXPECT--
bool(false)
cancelled
int(42)
bool(false)
int(43)
int(499999500000)
int(499999500000)
bool(false)
int(0)
bool(false)
int(0)
