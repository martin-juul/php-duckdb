--TEST--
PHP scalar functions: registered handler destructor visibility, resurrection and lazy semantics
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

class ReleaseScalar implements DuckDB\ScalarFunction
{
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
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
    $conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
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

$conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
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
    $conn = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
    $conn->registerScalarFunction('php_lazy', [], 'BIGINT', $lazy);
    unset($lazy);
    $conn = null;
    scalar_check($initializations === 0 && LazyReleaseScalar::$destructions === 0, 'Unused lazy handler stays uninitialized');
}
echo "lazy destructor semantics preserved\n";
?>
--EXPECT--
destructor visibility preserved
destructor resurrection preserved
lazy destructor semantics preserved
