--TEST--
PHP scalar functions: freeing a connection safely propagates registered handler destructor exit and fatal errors
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
require_once __DIR__ . '/subprocess.inc';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$child = <<<'PHP'
<?php
register_shutdown_function(function () {
    echo "shutdown\n";
});
final class DyingRegistration implements DuckDB\ScalarFunction
{
    public function __construct(private string $failure)
    {
    }
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        throw new LogicException('This function must never execute');
    }
    public function __destruct()
    {
        echo "function destructor\n";
        if ($this->failure === 'exit') {
            exit(7);
        }
        eval('class ScalarRegistrationTwice {} class ScalarRegistrationTwice {}');
    }
}
$conn = (new DuckDB\Database())->connect();
$handler = new DyingRegistration($argv[1]);
$conn->registerScalarFunction('php_dying_registration', ['BIGINT'], 'BIGINT', $handler);
unset($handler);
echo "before free\n";
unset($conn);
echo "not reached\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-connection-destructor-');
file_put_contents($path, $child);
register_shutdown_function(function () use ($path) {
    @unlink($path);
});
$args = duckdb_subprocess_args();
foreach (['exit', 'fatal'] as $failure) {
    // Match the COPY fatal regression: PHP's abandoned eval frames are
    // reclaimed by Zend; native allocations still pass through Memcheck.
    $env = $failure === 'fatal' ? ['USE_ZEND_ALLOC' => '1'] : [];
    $command = array_merge([PHP_BINARY], $args, [$path, $failure]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
    check(is_resource($process), 'Child process started');
    stream_set_blocking($pipes[1], false);
    $output = '';
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
            throw new RuntimeException("Child exceeded {$timeout}s: $failure $output");
        }
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    check($exit === ($failure === 'exit' ? 7 : 255), "$failure status: $exit $output");
    check(str_starts_with($output, "before free\nfunction destructor\n")
        && str_ends_with($output, "shutdown\n") && !str_contains($output, 'not reached'),
        "$failure propagates at the connection free boundary: $output");
    if ($failure === 'exit') {
        check($output === "before free\nfunction destructor\nshutdown\n", "exit output: $output");
    } else {
        check(preg_match('/Cannot (re)?declare class ScalarRegistrationTwice/', $output) === 1,
            "Original destructor fatal survives: $output");
    }
    echo "$failure\n";
}
?>
--EXPECT--
exit
fatal
