--TEST--
Scalar callbacks: releasing a connection preserves handler destructor exit and fatal errors
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
final class DestructorScalar implements DuckDB\ScalarFunction
{
    public function bind(array $argumentTypes, array $constantArguments): DuckDB\ScalarFunctionBinding
    {
        throw new RuntimeException('Not executed');
    }

    public function __destruct()
    {
        echo "handler destructor\n";
        if ($GLOBALS['scenario'] === 'exit') {
            exit(7);
        }
        trigger_error('Scalar destructor fatal', E_USER_ERROR);
    }
}

// E_USER_ERROR still exercises fatal bailout; PHP 8.4 deprecates triggering it.
error_reporting(E_ALL & ~E_DEPRECATED);
$scenario = $argv[1];
register_shutdown_function(fn() => print("shutdown\n"));
$conn = (new DuckDB\Database())->connect();
$conn->registerScalarFunction('php_destructor', ['BIGINT'], 'BIGINT', new DestructorScalar());
unset($conn);
echo "ran after destructor\n";
PHP;
$path = tempnam(sys_get_temp_dir(), 'duckdb_scalar_destructor_');
file_put_contents($path, $child);
try {
    foreach (['exit', 'fatal'] as $scenario) {
        $command = array_merge([PHP_BINARY], duckdb_subprocess_args(), [$path, $scenario]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        scalar_check($status === ($scenario === 'exit' ? 7 : 255), "$scenario status: $status");
        scalar_check(str_contains($output, 'handler destructor'), "$scenario destructor ran");
        scalar_check(str_contains($output, 'shutdown'), "$scenario shutdown ran");
        scalar_check(!str_contains($output, 'ran after destructor'), $output);
        echo "$scenario stops at connection destruction\n";
    }
} finally {
    unlink($path);
}

class ReleaseScalar implements DuckDB\ScalarFunction
{
    public function bind(array $argumentTypes, array $constantArguments): DuckDB\ScalarFunctionBinding
    {
        throw new RuntimeException('Not executed');
    }
}

final class PrivateReleaseScalar extends ReleaseScalar
{
    private function __destruct()
    {
        throw new RuntimeException('Private destructor ran');
    }
}

final class ProtectedReleaseScalar extends ReleaseScalar
{
    protected function __destruct()
    {
        throw new RuntimeException('Protected destructor ran');
    }
}

foreach ([PrivateReleaseScalar::class, ProtectedReleaseScalar::class] as $class) {
    $conn = (new DuckDB\Database())->connect();
    $conn->registerScalarFunction('php_private', [], 'BIGINT', new $class());
    $error = scalar_failure(function () use (&$conn) {
        $conn = null;
    });
    scalar_check($error instanceof Error && str_contains($error->getMessage(), '__destruct()'), $error->getMessage());
    unset($error);
}
echo "destructor visibility preserved\n";

final class ResurrectingReleaseScalar extends ReleaseScalar
{
    public static int $destructions = 0;
    public string $marker = 'alive';

    public function __destruct()
    {
        self::$destructions++;
        $GLOBALS['resurrected'] = $this;
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->registerScalarFunction('php_resurrect', [], 'BIGINT', new ResurrectingReleaseScalar());
$conn = null;
scalar_check($resurrected->marker === 'alive', 'Resurrected handler remains usable');
unset($resurrected);
scalar_check(ResurrectingReleaseScalar::$destructions === 1, 'Destructor runs only once');
echo "destructor resurrection preserved\n";

final class LazyReleaseScalar extends ReleaseScalar
{
    public static int $destructions = 0;
    public string $marker;

    public function __destruct()
    {
        self::$destructions++;
    }
}

if (PHP_VERSION_ID >= 80400) {
    $initializations = 0;
    $lazy = (new ReflectionClass(LazyReleaseScalar::class))->newLazyGhost(function () use (&$initializations) {
        $initializations++;
    });
    $conn = (new DuckDB\Database())->connect();
    $conn->registerScalarFunction('php_lazy', [], 'BIGINT', $lazy);
    unset($lazy);
    $conn = null;
    scalar_check($initializations === 0 && LazyReleaseScalar::$destructions === 0, 'Unused lazy handler stays uninitialized');
}
echo "lazy destructor semantics preserved\n";
?>
--EXPECT--
exit stops at connection destruction
fatal stops at connection destruction
destructor visibility preserved
destructor resurrection preserved
lazy destructor semantics preserved
