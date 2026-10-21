--TEST--
PHP scalar functions: exit and fatal errors unwind callback work before process shutdown
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
error_reporting(E_ALL & ~E_DEPRECATED);
[$stage, $failure] = [$argv[1], $argv[2]];
register_shutdown_function(function () {
    echo "shutdown\n";
});
final class ExitingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function __construct(private string $stage, private string $failure)
    {
    }
    private function visit(string $stage): void
    {
        if ($this->stage !== $stage) {
            return;
        }
        if ($this->failure === 'exit') {
            exit(7);
        }
        trigger_error('Scalar callback fatal', E_USER_ERROR);
    }
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        $this->visit('bind');
        return $this;
    }
    public function init(): DuckDB\ScalarExecutor
    {
        $this->visit('init');
        return $this;
    }
    public function execute(DuckDB\DataChunk $arguments): array
    {
        $this->visit('execute');
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}
$conn = (new DuckDB\Database(':memory:', ['threads' => getenv('DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY') ? 4 : 1]))->connect();
$conn->registerScalarFunction('php_bailout', ['BIGINT'], 'BIGINT', new ExitingScalar($stage, $failure));
$conn->query('SELECT php_bailout(i) FROM range(5000) t(i)')->fetchAll();
echo "not reached\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-bailout-');
file_put_contents($path, $child);
register_shutdown_function(function () use ($path) {
    @unlink($path);
});
$args = duckdb_subprocess_args();
$cases = [
    ['bind', []],
    ['init', []],
    ['execute', []],
    ['execute', ['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY' => '1']],
];
foreach ($cases as [$stage, $env]) {
    foreach (['exit', 'fatal'] as $failure) {
        $command = array_merge([PHP_BINARY], $args, [$path, $stage, $failure]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env + getenv());
        check(is_resource($process), 'Child process started');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        check($status === ($failure === 'exit' ? 7 : 255), "$stage $failure status: $status $output");
        check(!str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"),
            "$stage $failure unwinds through shutdown: $output");
        if ($failure === 'exit') {
            check($output === "shutdown\n", "exit preserves its status and does not resume SQL: $output");
        } else {
            check(str_contains($output, 'Scalar callback fatal'),
                "Original fatal error survives: $output");
        }
    }
    echo $stage, $env === [] || !isset($env['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY']) ? ' direct' : ' marshaled', "\n";
}
?>
--EXPECT--
bind direct
init direct
execute direct
execute marshaled
