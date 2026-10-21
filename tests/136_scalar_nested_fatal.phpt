--TEST--
Scalar callbacks: runtime fatal errors unwind nested array_map and generator callbacks
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
[$phase, $action] = [$argv[2], $argv[3]];
register_shutdown_function(function () {
    echo "shutdown\n";
});
$db = new DuckDB\Database(':memory:', ['threads' => getenv('DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY') ? 4 : 1]);
$conn = $db->connect();
$function = new TestScalar();
$function->hooks[$phase] = function () use ($action) {
    if ($action === 'generator') {
        $values = (static function () {
            yield 1;
            trigger_error('scalar callback fatal', E_USER_ERROR);
        })();
        foreach ($values as $value) {
        }

        return;
    }

    // An assigned return has a VM slot. Plain PHP itself leaks the C-local
    // return slot of an unused array_map() result when its callback is fatal.
    $mapped = array_map(static function (int $value) {
        trigger_error('scalar callback fatal', E_USER_ERROR);
    }, [1, 2]);
};
$conn->registerScalarFunction('php_value', ['BIGINT'], 'BIGINT', $function);
$conn->query('SELECT php_value(range) FROM range(5000)');
echo "not reached\n";
PHP;

$path = tempnam(sys_get_temp_dir(), 'duckdb-scalar-bailout');
file_put_contents($path, $child);
register_shutdown_function(fn() => @unlink($path));
$args = duckdb_subprocess_args();
foreach (['direct' => '0', 'marshaled' => '1'] as $mode => $serviceOnly) {
    foreach (['bind', 'init', 'execute'] as $phase) {
        foreach (['array_map', 'generator'] as $action) {
            $command = array_merge([PHP_BINARY], $args, [$path, __DIR__ . '/scalar.inc', $phase, $action]);
            $env = ['DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY' => $serviceOnly] + getenv();
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $status = proc_close($process);
            $label = "$mode $phase $action";
            scalar_check($status === 255, "$label: $status $output");
            scalar_check(str_contains($output, 'scalar callback fatal')
                && !str_contains($output, 'not reached') && str_ends_with($output, "shutdown\n"), "$label: $output");
        }
    }
    echo "$mode\n";
}
?>
--EXPECT--
direct
marshaled
