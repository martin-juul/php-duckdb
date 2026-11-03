--TEST--
Leased pump: nested connections, long callback-free statements, parse-error fallback and parked helpers at shutdown
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip duckdb cannot be loaded by a subprocess');
}
?>
--FILE--
<?php
require __DIR__ . '/copy.inc';
require_once __DIR__ . '/subprocess.inc';

final class OneScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

$dir = copy_scratch();
$db = new DuckDB\Database();
$a = $db->connect();
$b = $db->connect();
$a->query('SET threads = 4');
$format = new LinesFormat();
$a->registerCopyToFunction('lines', $format);
// B is pumped because it has a registration of its own.
$b->registerCopyToFunction('lines', new LinesFormat());

// A COPY handler on A runs pumped statements on B: both complete.
$nested = [];
$format->hooks['write'] = function () use ($b, $dir, &$nested) {
    if ($nested !== []) {
        return;
    }
    $b->query("COPY (SELECT range AS i FROM range(1000)) TO '$dir/b.csv' (FORMAT csv, HEADER false)");
    $nested[] = $b->query('SELECT count(*) AS n FROM range(100000)')->fetchAll()[0]['n'];
};
$a->query("COPY (SELECT range AS i FROM range(5000) ORDER BY i) TO '$dir/a.lines' (FORMAT lines)");
$format->hooks = [];
check(array_column(copy_lines("$dir/a.lines"), 0) === range(0, 4999), 'The outer COPY writes every row');
check(array_map('intval', file("$dir/b.csv")) === range(0, 999), 'The nested COPY writes every row');
check($nested === [100000], 'The nested query returns its rows: ' . json_encode($nested));
echo "nested connections\n";

$a->registerScalarFunction('php_id', ['BIGINT'], 'BIGINT', new OneScalar());

// A later syntax error sends the query through duckdb_query(), which DuckDB
// 2.0 runs up to the error; PHP callbacks bind and run from the helper.
$error = failure(fn() => $a->query('CREATE TABLE x AS SELECT php_id(41) + 1 AS v; SELEC 1'));
check($error instanceof DuckDB\ParserException, get_class($error) . ': ' . $error->getMessage());
check($a->query('SELECT v FROM x')->fetchAll() === [['v' => 42]], 'The statement before the error ran');
$format->reset();
$error = failure(fn() => $a->query("COPY (SELECT 7 AS i) TO '$dir/fallback.lines' (FORMAT lines); SELEC 1"));
check($error instanceof DuckDB\ParserException, get_class($error) . ': ' . $error->getMessage());
check(copy_lines("$dir/fallback.lines") === [[7]], 'The COPY before the error wrote its row');
check(array_values(array_diff($format->log, ['write'])) === ['bind', 'open', 'close'], json_encode($format->log));
echo "parse-error fallback\n";

/*
 * The children shorten the stuck limit to one second and size a
 * callback-free statement to run well past it on one DuckDB thread.
 */
$child = <<<'PHP'
<?php
require $argv[1];
[$scenario] = [$argv[2]];
register_shutdown_function(function () {
    echo "shutdown\n";
});
final class SlowScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public ?Closure $hook = null;
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }
    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }
    public function execute(DuckDB\DataChunk $arguments): array
    {
        if ($this->hook !== null) {
            ($this->hook)();
            $this->hook = null;
        }
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}
$db = new DuckDB\Database(':memory:', ['threads' => 1]);
$a = $db->connect();
$b = $db->connect();
// Registered but unused, so B's statements are pumped without callbacks.
$b->registerScalarFunction('php_unused', ['BIGINT'], 'BIGINT', new SlowScalar());
$long = function (int $rows) use ($b): int {
    return $b->query("SELECT count(*) AS n FROM range($rows) t(i) WHERE hash(i) % 7 = 3")->fetchAll()[0]['n'];
};
// Warm up first: Valgrind runs new code far slower the first time.
$rows = 100000;
$long($rows);
do {
    $started = hrtime(true);
    $long($rows);
    $elapsed = (hrtime(true) - $started) / 1e9;
    if ($elapsed < 0.25) {
        $rows *= 4;
    }
} while ($elapsed < 0.25);
$rows = (int) ceil($rows * 2.5 / $elapsed);
switch ($scenario) {
    case 'long':
        $started = hrtime(true);
        $long($rows);
        $elapsed = (hrtime(true) - $started) / 1e9;
        echo $elapsed > 1 ? "long statement completed\n" : "statement too short: $elapsed\n";
        break;
    case 'unserviced':
        // A's callback runs B's long statement, so A's other workers wait
        // unserviced while the request thread is inside B's lease.
        // Table scans run in parallel, unlike range().
        $a->query('CREATE TABLE t AS SELECT range AS i FROM range(1000000)');
        $a->query('SET threads = 4');
        $function = new SlowScalar();
        $inner = null;
        $function->hook = function () use ($long, $rows, &$inner) {
            $inner = $long($rows);
        };
        $a->registerScalarFunction('php_slow', ['BIGINT'], 'BIGINT', $function);
        try {
            $a->query('SELECT sum(php_slow(i)) FROM t');
            echo "not reached\n";
        } catch (DuckDB\Exception $error) {
            echo get_class($error), ': ', $error->getMessage(), "\n";
        }
        echo is_int($inner) ? "nested statement completed\n" : "nested statement missing\n";
        echo $a->query('SELECT 1 AS ok')->fetchAll()[0]['ok'], "\n";
        break;
    case 'parked':
        // Keep a connection with a parked helper alive until shutdown.
        $GLOBALS['kept'] = $b;
        echo "parked\n";
        break;
}
PHP;
file_put_contents("$dir/child.php", $child);
$args = duckdb_subprocess_args();

function run_child(string $dir, array $args, string $scenario): array
{
    $command = array_merge([PHP_BINARY], $args, ["$dir/child.php", __DIR__ . '/copy.inc', $scenario]);
    $env = ['DUCKDB_PHP_COPY_STUCK_SECONDS' => '1'] + getenv();
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
    check(is_resource($process), 'Child process started');
    stream_set_blocking($pipes[1], false);
    $output = '';
    // Bound a deadlock while allowing for a loaded machine.
    $deadline = microtime(true) + 120;
    do {
        $output .= stream_get_contents($pipes[1]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            proc_close($process);
            throw new RuntimeException("Child exceeded 120s: $scenario $output");
        }
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $output];
}

// A long statement without callbacks never trips the stuck guard.
[$status, $output] = run_child($dir, $args, 'long');
check($status === 0 && $output === "long statement completed\nshutdown\n", "long: $status $output");
echo "long statement\n";

// The outer connection's callbacks fail after the limit instead of hanging.
[$status, $output] = run_child($dir, $args, 'unserviced');
check($status === 0, "unserviced status: $status $output");
check(preg_match('/^DuckDB\\\\\\w*Exception: .*COPY callback abandoned: the driving thread stayed inside DuckDB too long'
    . '\\nnested statement completed\\n1\\nshutdown\\n$/', $output) === 1, "unserviced: $output");
echo "unserviced outer connection\n";

[$status, $output] = run_child($dir, $args, 'parked');
check($status === 0 && $output === "parked\nshutdown\n", "parked: $status $output");
echo "parked helper at shutdown\n";

// This request also ends with parked helpers on live connections.
$GLOBALS['kept'] = [$a, $b];
?>
--EXPECT--
nested connections
parse-error fallback
long statement
unserviced outer connection
parked helper at shutdown
