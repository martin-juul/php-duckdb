--TEST--
Swoole 6+: PendingQuery::suspend() yields the coroutine (scheduler stays responsive)
--VALGRIND-SKIP--
Concurrency/timing assertions are meaningless under Memcheck, and Swoole's
own runtime is not part of what the valgrind stage validates.
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
if (!extension_loaded('swoole')) {
    die('skip swoole extension not loaded');
}
if (version_compare(SWOOLE_VERSION, '6.0', '<')) {
    die('skip swoole >= 6.0 required, found ' . SWOOLE_VERSION);
}
require_once __DIR__ . '/swoole_subprocess.inc';
if (duckdb_swoole_spawn_args() === null) {
    die('skip cannot locate duckdb.so for the subprocess '
        . '(set DUCKDB_EXTENSION_PATH, or run via tests/harness.php)');
}
?>
--FILE--
<?php
// Swoole logs curl-hook warnings to stderr on every Coroutine\run() when
// ext-curl is absent; run-tests merges stderr into the compared output,
// so the scenario runs in a subprocess with stderr discarded.
require_once __DIR__ . '/swoole_subprocess.inc';
$phpArgs = duckdb_swoole_spawn_args();
if ($phpArgs === null) {
    die("subprocess spawn args unresolved (skipif should have caught this)\n");
}

$script = <<<'PHP'
use DuckDB\Database;
use DuckDB\FetchMode;
use DuckDB\InterruptedException;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

const HEAVY = 'SELECT sum(t1.range) FROM range(20000000) t1, range(20) t2';
const HEAVY_RESULT = 3999999800000000;

$db = new Database(':memory:');

// While one coroutine is suspended on a heavy query, a peer coroutine
// must keep getting scheduler time. If suspend() blocked the scheduler,
// the ticker would starve.
run(function () use ($db) {
    $ticks = 0;
    $stop = false;
    $ticker = Coroutine::create(function () use (&$ticks, &$stop) {
        while (!$stop) {
            Coroutine::sleep(0.005);
            $ticks++;
        }
    });
    $res = null;
    $worker = Coroutine::create(function () use ($db, &$res) {
        $res = $db->connect()->queryAsync(HEAVY)->suspend()->fetchAll(FetchMode::Num)[0][0];
    });
    Coroutine::join([$worker]);
    $stop = true;
    Coroutine::join([$ticker]);
    printf("worker result: %d\n", $res);
    printf("scheduler responsive while suspended: %s (%d ticks)\n", $ticks >= 5 ? 'yes' : 'no', $ticks);
});

// Two concurrent queries on separate connections both return correctly.
run(function () use ($db) {
    $out = [];
    $cids = [];
    foreach ([1, 2] as $n) {
        $cids[] = Coroutine::create(function () use ($db, &$out, $n) {
            $out[$n] = $db->connect()->queryAsync(HEAVY)->suspend()->fetchAll(FetchMode::Num)[0][0];
        });
    }
    Coroutine::join($cids);
    printf("concurrent results correct: %s\n",
        $out[1] === HEAVY_RESULT && $out[2] === HEAVY_RESULT ? 'yes' : 'no');
});

// Single-threaded polling mode also yields the coroutine between slices.
run(function () use ($db) {
    $r = $db->connect()->queryPending('SELECT count(*) FROM range(50000000)')->suspend();
    printf("polling: %d\n", $r->fetchAll(FetchMode::Num)[0][0]);
});

// Prepared statements: executeAsync + suspend.
run(function () use ($db) {
    $stmt = $db->connect()->prepare('SELECT ?::INTEGER + 1');
    printf("stmt: %d\n", $stmt->executeAsync([41])->suspend()->fetchAll(FetchMode::Num)[0][0]);
});

// Cancellation from a peer coroutine surfaces as InterruptedException.
run(function () use ($db) {
    $conn = $db->connect();
    $p = $conn->queryAsync('SELECT count(*) FROM range(100000000) t1, range(10000) t2');
    Coroutine::create(function () use ($p) {
        Coroutine::sleep(0.05);
        $p->cancel();
    });
    try {
        $p->suspend();
        echo "cancel: not interrupted\n";
    } catch (InterruptedException) {
        echo "cancel: interrupted\n";
    }
});

// With Swoole loaded but outside a coroutine, suspend() keeps its
// fiber-contract error.
$p = $db->connect()->queryAsync('SELECT 1');
try {
    $p->suspend();
    echo "outside: no error\n";
} catch (Error $e) {
    echo "outside: ", $e->getMessage(), "\n";
}
PHP;

// Swoole writes its log lines straight to fd 1, bypassing PHP's output
// layer (and stderr), so the subprocess's stderr redirect alone is not
// enough: filter Swoole's `[timestamp %pid] TAB LEVEL TAB` log lines out
// of the captured stdout. Our scenario lines never start with '['.
$proc = proc_open(
    array_merge([PHP_BINARY], $phpArgs, ['-r', $script]),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$code = proc_close($proc);
foreach (explode("\n", (string) $out) as $line) {
    if ($line === '' || preg_match('/^\[\d{4}-\d{2}-\d{2} .*\]\t[A-Z]+\t/', $line) === 1) {
        continue;
    }
    echo $line, "\n";
}
if ($code !== 0) {
    echo "subprocess exited with $code\n";
}
?>
--EXPECTF--
worker result: 3999999800000000
scheduler responsive while suspended: yes (%d ticks)
concurrent results correct: yes
polling: 50000000
stmt: 42
cancel: interrupted
outside: Cannot suspend outside of a fiber
