--TEST--
COPY TO: leased execution with and without DuckDB workers, exit() and fatal errors in handlers, stuck leases and interrupts
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

/*
 * Each scenario runs in a child process. The connection's helper thread
 * drives every statement and hands each callback to the request thread.
 * "helper" uses one DuckDB thread, so the helper reaches every callback;
 * "workers" adds DuckDB worker threads that reach them too.
 */
$child = <<<'PHP'
<?php
require $argv[1];
[$scenario, $out, $threads] = [$argv[2], $argv[3], $argv[4]];
register_shutdown_function(function () {
    echo "shutdown\n";
});
$conn = (new DuckDB\Database())->connect();
$conn->query("SET threads = $threads");
$format = new LinesFormat();
$format->hooks['abort'] = function (Throwable $reason) {
    echo 'abort: ', $reason->getMessage(), "\n";
};
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(5000)) TO '$out' (FORMAT lines)";
$writes = 0;
switch ($scenario) {
    case 'copy':
        $conn->query($copy);
        echo count(file($out)), " rows\n";
        break;
    case 'exit':
        $format->hooks['write'] = function () use (&$writes) {
            if (++$writes === 2) {
                exit(7);
            }
        };
        $conn->query($copy);
        echo "not reached\n";
        break;
    case 'suspend-exit':
        $exited = false;
        $format->hooks['write'] = function () use (&$writes, &$exited) {
            if (++$writes === 2) {
                $exited = true;
                exit(7);
            }
        };
        $fiber = new Fiber(fn() => $conn->queryPending($copy)->suspend());
        $fiber->start();
        while (!$fiber->isTerminated()) {
            if ($exited) {
                echo "ran after exit\n";
            }
            $fiber->resume();
        }
        echo "not reached\n";
        break;
    case 'fatal':
        $format->hooks['write'] = function () use (&$writes) {
            if (++$writes === 2) {
                eval('class Twice {} class Twice {}');
            }
        };
        $conn->query($copy);
        echo "not reached\n";
        break;
    case 'stuck':
        try {
            $conn->query($copy);
            echo "not reached\n";
        } catch (DuckDB\Exception $error) {
            echo get_class($error), ': ', $error->getMessage(), "\n";
        }
        echo $conn->query('SELECT 1 AS ok')->fetchAll()[0]['ok'], "\n";
        break;
    case 'interrupt':
        $format->hooks['write'] = function () use ($conn, &$writes) {
            if (++$writes === 2) {
                $conn->interrupt();
            }
        };
        try {
            $conn->query($copy);
            echo "not reached\n";
        } catch (DuckDB\Exception $error) {
            echo get_class($error), "\n";
        }
        echo $conn->query('SELECT 1 AS ok')->fetchAll()[0]['ok'], "\n";
        break;
}
// Hooks that capture $conn form a cycle through its registration, and PHP
// does not collect cycles at shutdown.
$format->hooks = [];
PHP;

$dir = copy_scratch();
file_put_contents("$dir/child.php", $child);
$args = duckdb_subprocess_args();

function run_child(string $dir, array $args, string $scenario, int $threads, array $env = []): array
{
    $command = array_merge([PHP_BINARY], $args,
        ["$dir/child.php", __DIR__ . '/copy.inc', $scenario, "$dir/$scenario.lines", (string) $threads]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

$modes = [
    'helper' => 1,
    'workers' => 4,
];
foreach ($modes as $mode => $threads) {
    [$status, $output] = run_child($dir, $args, 'copy', $threads);
    check($status === 0 && $output === "5000 rows\nshutdown\n", "$mode copy: $status $output");

    foreach (['exit', 'suspend-exit'] as $scenario) {
        [$status, $output] = run_child($dir, $args, $scenario, $threads);
        check($status === 7, "$mode $scenario status: $status $output");
        check(preg_match('/^abort: .*COPY aborted by exit\\(\\)\nshutdown\n$/', $output) === 1,
            "$mode $scenario: $output");
    }

    // PHP abandons the handler's frames on a fatal error and never frees
    // their values; with USE_ZEND_ALLOC=0 Memcheck reports that engine leak
    // for any nested fatal, with or without this extension. Zend's allocator
    // releases them, while Memcheck still checks invalid accesses and native
    // leaks on this path.
    [$status, $output] = run_child($dir, $args, 'fatal', $threads, ['USE_ZEND_ALLOC' => '1']);
    check($status === 255, "$mode fatal status: $status $output");
    check(preg_match('/Cannot (re)?declare class Twice/', $output) === 1 && !str_contains($output, 'abort')
        && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"), "$mode fatal: $output");

    [$status, $output] = run_child($dir, $args, 'interrupt', $threads);
    check($status === 0, "$mode interrupt status: $status $output");
    check(preg_match('/^abort: .*\nDuckDB\\\\(Interrupted)?Exception\n1\nshutdown\n$/', $output) === 1, "$mode interrupt: $output");
    echo "$mode\n";
}

// A lease that never services its callbacks fails them after the limit
// instead of hanging, and the connection stays usable.
foreach ($modes as $mode => $threads) {
    [$status, $output] = run_child($dir, $args, 'stuck', $threads, [
        'DUCKDB_PHP_TEST_STUCK_LEASE' => '1',
        'DUCKDB_PHP_COPY_STUCK_SECONDS' => '1',
    ]);
    check($status === 0, "$mode stuck status: $status $output");
    check($output === "DuckDB\\Exception: Invalid Input Error: COPY callback abandoned: "
        . "the driving thread stayed inside DuckDB too long\n1\nshutdown\n", "$mode stuck: $output");
    echo "$mode stuck lease\n";
}
?>
--EXPECT--
helper
workers
helper stuck lease
workers stuck lease
