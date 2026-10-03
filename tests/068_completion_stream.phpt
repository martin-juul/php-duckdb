--TEST--
Async completion streams are selectable and survive early close and handle destruction
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$c = (new DuckDB\Database())->connect();

function waitForCompletion($stream): void {
    $read = [$stream];
    $write = $except = null;
    if (stream_select($read, $write, $except, 10) !== 1) {
        throw new RuntimeException('Completion stream did not become readable');
    }
    if (fread($stream, 1) !== "\x01") {
        throw new RuntimeException('Missing completion notification');
    }
}

foreach ([false, true] as $prepared) {
    $p = $prepared ? $c->prepare('SELECT ?::INTEGER AS n')->executeAsync([42])
                   : $c->queryAsync('SELECT 42 AS n');
    $stream = $p->getStream();
    waitForCompletion($stream);
    var_dump($p->await()->fetchRow()['n']);
    try {
        $p->getStream();
    } catch (DuckDB\Exception $e) {
        echo "stream transferred once\n";
    }
    fclose($stream);
}

// The worker must tolerate a reader being closed before notification.
for ($i = 0; $i < 5; $i++) {
    $p = $c->queryAsync('SELECT sum(i)::BIGINT AS n FROM range(1000000) t(i)');
    fclose($p->getStream());
    if ($p->await()->fetchRow()['n'] !== 499999500000) {
        throw new RuntimeException('Early close affected execution');
    }
}
echo "early close safe\n";

// A transferred stream remains usable after the PHP PendingQuery is gone.
$p = $c->queryAsync('SELECT 1');
$stream = $p->getStream();
unset($p);
waitForCompletion($stream);
fclose($stream);
echo "stream outlives handle\n";

$p = $c->queryPending('SELECT 1');
var_dump($p->getFd());
try {
    $p->getStream();
} catch (DuckDB\Exception $e) {
    echo "polling has no stream\n";
}
$p->await();
?>
--EXPECT--
int(42)
stream transferred once
int(42)
stream transferred once
early close safe
stream outlives handle
int(-1)
polling has no stream
