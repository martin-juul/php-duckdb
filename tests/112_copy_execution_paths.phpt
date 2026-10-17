--TEST--
COPY TO: direct and marshaled callbacks, exit() and fatal errors in handlers, stuck drivers and interrupts
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
 * Each scenario runs in a child process. "direct" uses one DuckDB thread, so
 * every callback runs on the request thread. "marshaled" stops the request
 * thread from executing DuckDB tasks, so every callback is handed over from
 * a worker thread.
 */
$child = <<<'PHP'
<?php
require $argv[1];
[$scenario, $out] = [$argv[2], $argv[3]];
register_shutdown_function(function () {
    echo "shutdown\n";
});
$conn = (new DuckDB\Database())->connect();
$conn->query(getenv('DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY') ? 'SET threads = 4' : 'SET threads = 1');
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

function run_child(string $dir, array $args, string $scenario, array $env): array
{
    $command = array_merge([PHP_BINARY], $args, ["$dir/child.php", __DIR__ . '/copy.inc', $scenario, "$dir/$scenario.lines"]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

$modes = [
    'direct' => [],
    'marshaled' => ['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY' => '1'],
];
foreach ($modes as $mode => $env) {
    [$status, $output] = run_child($dir, $args, 'copy', $env);
    check($status === 0 && $output === "5000 rows\nshutdown\n", "$mode copy: $status $output");

    foreach (['exit', 'suspend-exit'] as $scenario) {
        [$status, $output] = run_child($dir, $args, $scenario, $env);
        check($status === 7, "$mode $scenario status: $status $output");
        check(preg_match('/^abort: .*COPY aborted by exit\\(\\)\nshutdown\n$/', $output) === 1,
            "$mode $scenario: $output");
    }

    // PHP abandons the handler's frames on a fatal error and never frees
    // their values; with USE_ZEND_ALLOC=0 Memcheck reports that engine leak
    // for any nested fatal, with or without this extension. Zend's allocator
    // releases them, while Memcheck still checks invalid accesses and native
    // leaks on this path.
    [$status, $output] = run_child($dir, $args, 'fatal', $env + ['USE_ZEND_ALLOC' => '1']);
    check($status === 255, "$mode fatal status: $status $output");
    check(preg_match('/Cannot (re)?declare class Twice/', $output) === 1 && !str_contains($output, 'abort')
        && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"), "$mode fatal: $output");

    [$status, $output] = run_child($dir, $args, 'interrupt', $env);
    check($status === 0, "$mode interrupt status: $status $output");
    check(preg_match('/^abort: .*\nDuckDB\\\\(Interrupted)?Exception\n1\nshutdown\n$/', $output) === 1, "$mode interrupt: $output");
    echo "$mode\n";
}

// The stuck-driver safety net releases a waiting worker.
[$status, $output] = run_child($dir, $args, 'stuck', $modes['marshaled'] + [
    'DUCKDB_PHP_TEST_STUCK_DRIVER' => '1',
    'DUCKDB_PHP_COPY_STUCK_SECONDS' => '1',
]);
check($status === 0, "stuck status: $status $output");
check(str_contains($output, 'COPY callback abandoned: the driving thread stayed inside DuckDB too long'), $output);
echo "stuck driver\n";
?>
--EXPECT--
direct
marshaled
stuck driver
