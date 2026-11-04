--TEST--
Leased pump: exit(), fatal errors and memory_limit while marshaling end the request and leave the connection usable
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip duckdb cannot be loaded by a subprocess');
}
if (!function_exists('proc_open')) {
    die('skip proc_open is unavailable');
}
?>
--FILE--
<?php
require __DIR__ . '/copy.inc';
require_once __DIR__ . '/subprocess.inc';

/*
 * Each scenario ends its child process from inside a pumped statement. A
 * shutdown function then creates an object whose destructor queries the
 * same connection: it only completes when the bailout released the
 * connection's lock and its helper thread.
 */
$child = <<<'PHP'
<?php
require $argv[1];
[$scenario, $threads, $out] = [$argv[2], (int) $argv[3], $argv[4]];

final class Probe
{
    public function __construct(private DuckDB\Connection $conn)
    {
    }

    public function __destruct()
    {
        echo 'probe ', $this->conn->query('SELECT 42 AS v')->fetchAll()[0]['v'], "\n";
    }
}

$conn = (new DuckDB\Database(':memory:', ['threads' => $threads]))->connect();
register_shutdown_function(function () use ($conn) {
    echo "shutdown\n";

    // A fatal error skips the destructors of objects that already exist.
    $probe = new Probe($conn);
    unset($probe);
});

$format = new LinesFormat();
$format->hooks['abort'] = function (Throwable $reason) {
    echo 'abort: ', $reason->getMessage(), "\n";
};
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(5000)) TO '$out' (FORMAT lines)";

// A string option far larger than the memory left once it is set, so
// decoding it for a handler's arguments exceeds memory_limit.
$payload = str_repeat('x', 8 << 20);
$large = "COPY (SELECT 1 AS i) TO '$out' (FORMAT lines, payload '$payload')";
unset($payload);
$tighten = function () {
    ini_set('memory_limit', (string) (memory_get_usage(true) + (4 << 20)));
};

$writes = 0;
switch ($scenario) {
    case 'exit':
        $format->hooks['write'] = function () use (&$writes) {
            if (++$writes === 2) {
                exit(7);
            }
        };
        $conn->query($copy);
        break;
    case 'fatal':
        $format->hooks['write'] = function () use (&$writes) {
            if (++$writes === 2) {
                eval('class Twice {} class Twice {}');
            }
        };
        $conn->query($copy);
        break;
    case 'memory bind':
        // bind() runs on this thread while the statement is prepared.
        $tighten();
        $conn->query($large);
        break;
    case 'memory marshaled bind':
        // A later syntax error runs the query on the helper thread, which
        // hands bind() to this thread.
        $fallback = "$large; SELEC 1";
        $tighten();
        $conn->query($fallback);
        break;
    case 'memory open':
        // bind() keeps its options, so open() needs a second copy.
        $format->hooks['bind'] = $tighten;
        $conn->query($large);
        break;
}
echo "not reached\n";
PHP;

$dir = copy_scratch();
file_put_contents("$dir/child.php", $child);
$args = duckdb_subprocess_args();

function run_child(string $dir, array $args, string $scenario, int $threads, array $env = []): array
{
    $command = array_merge([PHP_BINARY], $args,
        ["$dir/child.php", __DIR__ . '/copy.inc', $scenario, (string) $threads, "$dir/out.lines"]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
    check(is_resource($process), 'Child process started');
    stream_set_blocking($pipes[1], false);
    $output = '';
    // Preserve a deadlock bound while allowing Memcheck's slower execution.
    $timeout = getenv('USE_ZEND_ALLOC') === '0' ? 300 : 60;
    $deadline = microtime(true) + $timeout;
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
            throw new RuntimeException("Child exceeded {$timeout}s: $scenario $output");
        }
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $output];
}

// PHP never frees the values a fatal error abandons; Zend's allocator
// releases them, while Memcheck still checks invalid accesses and native
// leaks. memory_limit also needs Zend's allocator.
$fatal = ['USE_ZEND_ALLOC' => '1'];

// "helper": the connection's helper thread is the only DuckDB thread;
// "workers": DuckDB worker threads reach the callbacks too.
foreach (['helper' => 1, 'workers' => 4] as $mode => $threads) {
    [$status, $output] = run_child($dir, $args, 'exit', $threads);
    check($status === 7, "$mode exit status: $status $output");
    check(preg_match('/^abort: .*COPY aborted by exit\\(\\)\nshutdown\nprobe 42\n$/', $output) === 1,
        "$mode exit: $output");
    echo "$mode exit\n";

    [$status, $output] = run_child($dir, $args, 'fatal', $threads, $fatal);
    check($status === 255, "$mode fatal status: $status $output");
    check(preg_match('/Cannot (re)?declare class Twice/', $output) === 1 && !str_contains($output, 'abort')
        && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\nprobe 42\n"),
        "$mode fatal: $output");
    echo "$mode fatal\n";

    foreach (['memory bind', 'memory marshaled bind', 'memory open'] as $scenario) {
        [$status, $output] = run_child($dir, $args, $scenario, $threads, $fatal);
        check($status === 255, "$mode $scenario status: $status $output");
        check(str_contains($output, 'Allowed memory size') && !str_contains($output, 'abort')
            && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\nprobe 42\n"),
            "$mode $scenario: $output");
        echo "$mode $scenario\n";
    }
}
?>
--EXPECT--
helper exit
helper fatal
helper memory bind
helper memory marshaled bind
helper memory open
workers exit
workers fatal
workers memory bind
workers memory marshaled bind
workers memory open
