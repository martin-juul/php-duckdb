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
[$stage, $failure, $threads, $sql] = [$argv[1], $argv[2], (int) $argv[3], $argv[4]];
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
$conn = (new DuckDB\Database(':memory:', ['threads' => $threads]))->connect();
$conn->registerScalarFunction('php_bailout', ['BIGINT'], 'BIGINT', new ExitingScalar($stage, $failure));
$conn->query($sql)->fetchAll();
echo "not reached\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-bailout-');
file_put_contents($path, $child);
register_shutdown_function(function () use ($path) {
    @unlink($path);
});
$args = duckdb_subprocess_args();
$sql = 'SELECT php_bailout(i) FROM range(5000) t(i)';
// A later syntax error makes the pump run the query as one duckdb_query()
// on the helper thread, which hands bind() over to the request thread.
$fallback = "$sql; SELEC 1";
$cases = [
    'bind direct' => ['bind', 1, $sql],
    'bind marshaled' => ['bind', 1, $fallback],
    'init helper' => ['init', 1, $sql],
    'execute helper' => ['execute', 1, $sql],
    'execute workers' => ['execute', 4, $sql],
];
foreach ($cases as $label => [$stage, $threads, $query]) {
    foreach (['exit', 'fatal'] as $failure) {
        $command = array_merge([PHP_BINARY], $args, [$path, $stage, $failure, (string) $threads, $query]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, getenv());
        check(is_resource($process), 'Child process started');
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        check($status === ($failure === 'exit' ? 7 : 255), "$label $failure status: $status $output");
        check(!str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"),
            "$label $failure unwinds through shutdown: $output");
        if ($failure === 'exit') {
            check($output === "shutdown\n", "exit preserves its status and does not resume SQL: $output");
        } else {
            check(str_contains($output, 'Scalar callback fatal'),
                "Original fatal error survives: $output");
        }
    }
    echo $label, "\n";
}
?>
--EXPECT--
bind direct
bind marshaled
init helper
execute helper
execute workers
