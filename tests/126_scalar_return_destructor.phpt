--TEST--
PHP scalar functions: unsupported return-object destructors may throw or exit without escaping native cleanup
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
final class UnsupportedScalarReturn
{
    public function __construct(private string $failure)
    {
    }
    public function __destruct()
    {
        echo "return destructor\n";
        if ($this->failure === 'exit') {
            exit(7);
        }
        throw new LogicException('Unsupported return destructor threw');
    }
}
final class DestructingReturnScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function __construct(private string $failure)
    {
    }
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
        echo "execute\n";
        $out = array_fill(0, $arguments->rowCount(), 1);
        $out[0] = new UnsupportedScalarReturn($this->failure);
        return $out;
    }
}
$conn = (new DuckDB\Database())->connect();
$conn->query(getenv('DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY') ? 'SET threads = 4' : 'SET threads = 1');
$conn->registerScalarFunction('php_bad_return', ['BIGINT'], 'BIGINT', new DestructingReturnScalar($argv[1]));
try {
    $conn->query('SELECT php_bad_return(i) FROM range(5000) t(i)')->fetchAll();
    echo "not reached\n";
} catch (DuckDB\Exception $error) {
    echo "caught conversion failure\n";
}
echo $conn->query('SELECT 42')->fetchColumn(), "\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-return-destructor-');
file_put_contents($path, $child);
register_shutdown_function(function () use ($path) {
    @unlink($path);
});
$args = duckdb_subprocess_args();

function run_child(string $path, array $args, string $failure, array $env): array
{
    $command = array_merge([PHP_BINARY], $args, [$path, $failure]);
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
            throw new RuntimeException("Child exceeded {$timeout}s: $failure $output");
        }
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
    return [$exit, $output];
}

foreach (['direct' => [], 'marshaled' => ['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY' => '1']] as $mode => $env) {
    [$status, $output] = run_child($path, $args, 'throw', $env);
    check($status === 0, "$mode throwing destructor status: $status $output");
    check($output === "execute\nreturn destructor\ncaught conversion failure\n42\nshutdown\n",
        "$mode throwing destructor fails once and leaves the connection reusable: $output");
    echo "$mode throw\n";

    [$status, $output] = run_child($path, $args, 'exit', $env);
    check($status === 7, "$mode exiting destructor status: $status $output");
    check($output === "execute\nreturn destructor\nshutdown\n",
        "$mode exit unwinds without another callback or resumed PHP code: $output");
    echo "$mode exit\n";
}
?>
--EXPECT--
direct throw
direct exit
marshaled throw
marshaled exit
