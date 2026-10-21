--TEST--
PHP scalar functions: worker init callbacks preserve exit and runtime fatal errors
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
require __DIR__ . '/scalar.inc';
require_once __DIR__ . '/subprocess.inc';

$child = <<<'PHP'
<?php
require $argv[1];
error_reporting(E_ALL & ~E_DEPRECATED);
$action = $argv[2];
register_shutdown_function(function () {
    echo "shutdown\n";
});
$conn = (new DuckDB\Database(':memory:', ['threads' => 4]))->connect();
$function = new TestScalar();
$function->hooks['init'] = function () use ($action) {
    if ($action === 'exit') {
        exit(7);
    }

    trigger_error('scalar worker init fatal', E_USER_ERROR);
};
$conn->registerScalarFunction('php_value', ['BIGINT'], 'BIGINT', $function);
$conn->query('SELECT php_value(range) FROM range(5000)');
echo "not reached\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-worker-init');
file_put_contents($path, $child);
register_shutdown_function(fn() => @unlink($path));
$args = duckdb_subprocess_args();
foreach (['exit', 'fatal'] as $action) {
    $command = array_merge([PHP_BINARY], $args, [$path, __DIR__ . '/scalar.inc', $action]);
    $env = ['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY' => '1'] + getenv();
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
    scalar_check(is_resource($process), 'Child process started');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if ($action === 'exit') {
        scalar_check($status === 7 && $output === "shutdown\n", "$action: $status $output");
    } else {
        scalar_check($status === 255, "$action: $status $output");
        scalar_check(str_contains($output, 'scalar worker init fatal')
            && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"), "$action: $output");
    }
    echo "worker init $action\n";
}
?>
--EXPECT--
worker init exit
worker init fatal
