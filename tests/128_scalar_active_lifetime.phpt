--TEST--
Scalar callbacks: active handlers survive releasing the last PHP connection reference
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
require __DIR__ . '/scalar.inc';
require __DIR__ . '/subprocess.inc';

$child = <<<'PHP'
<?php
$phase = $argv[1];
$armed = false;
$active = null;
$premature = false;
$finished = false;

function release_connection(object $handler, string $current): void
{
    if ($GLOBALS['armed'] && $GLOBALS['phase'] === $current) {
        $GLOBALS['conn'] = null;
        gc_collect_cycles();
    }
}

function finish_callback(string $current): void
{
    if ($GLOBALS['armed'] && $GLOBALS['phase'] === $current) {
        if ($GLOBALS['premature']) {
            throw new RuntimeException('Active handler destroyed before callback returned');
        }
        $GLOBALS['finished'] = true;
    }
    $GLOBALS['active'] = null;
}

trait LifetimeMarker
{
    public string $marker = 'alive';

    public function __destruct()
    {
        if ($GLOBALS['active'] === static::class) {
            $GLOBALS['premature'] = true;
        }
    }
}

final class LifetimeFunction implements DuckDB\ScalarFunction
{
    use LifetimeMarker;

    public function bind(array $argumentTypes, array $constantArguments): DuckDB\ScalarFunctionBinding
    {
        $GLOBALS['active'] = self::class;
        release_connection($this, 'bind');
        finish_callback('bind');
        if ($this->marker !== 'alive') {
            throw new RuntimeException('Function state corrupted');
        }
        return new LifetimeBinding();
    }
}

final class LifetimeBinding implements DuckDB\ScalarFunctionBinding
{
    use LifetimeMarker;

    public function init(): DuckDB\ScalarFunctionExecutor
    {
        $GLOBALS['active'] = self::class;
        release_connection($this, 'init');
        finish_callback('init');
        if ($this->marker !== 'alive') {
            throw new RuntimeException('Binding state corrupted');
        }
        return new LifetimeExecutor();
    }
}

final class LifetimeExecutor implements DuckDB\ScalarFunctionExecutor
{
    use LifetimeMarker;

    public function execute(DuckDB\DataChunk $input): DuckDB\Vector
    {
        $GLOBALS['active'] = self::class;
        release_connection($this, 'execute');
        finish_callback('execute');
        if ($this->marker !== 'alive') {
            throw new RuntimeException('Executor state corrupted');
        }
        return $input->vector(0);
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->registerScalarFunction('php_lifetime', ['BIGINT'], 'BIGINT', new LifetimeFunction());
if ($phase === 'bind') {
    $armed = true;
    $run = fn() => $GLOBALS['conn']->query('SELECT php_lifetime(42) AS v');
} else {
    $statement = $conn->prepare('SELECT php_lifetime(42) AS v');
    $armed = true;
    $run = fn() => $statement->execute();
}
try {
    $run()->fetchAll();
} catch (DuckDB\Exception $error) {
    // Releasing a registration can invalidate the remaining query work.
}
if ($premature || !$finished) {
    throw new RuntimeException("$phase active handler did not survive its callback");
}
echo "$phase active handler survived\n";
PHP;
$path = tempnam(sys_get_temp_dir(), 'duckdb_scalar_lifetime_');
file_put_contents($path, $child);
try {
    foreach (['bind', 'init', 'execute'] as $phase) {
        $command = array_merge([PHP_BINARY], duckdb_subprocess_args(), [$path, $phase]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        scalar_check($status === 0, "$phase status $status: $output");
        scalar_check(trim($output) === "$phase active handler survived", $output);
        echo $output;
    }
} finally {
    unlink($path);
}
?>
--EXPECT--
bind active handler survived
init active handler survived
execute active handler survived
