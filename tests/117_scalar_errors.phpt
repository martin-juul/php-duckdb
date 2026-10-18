--TEST--
PHP scalar functions: callback errors, output validation and connection recovery
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected operation to fail');
}

final class BrokenScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public string $stage = '';
    public ?Throwable $cause = null;

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        if ($this->stage === 'bind') {
            throw $this->cause;
        }
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        if ($this->stage === 'init') {
            throw $this->cause;
        }
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        if ($this->stage === 'execute') {
            throw $this->cause;
        }
        if ($this->stage === 'too short') {
            return [];
        }
        if ($this->stage === 'too long') {
            return [1, 2];
        }
        if ($this->stage === 'nonlist') {
            return ['row' => 1];
        }
        if ($this->stage === 'wrong type') {
            return ['not an integer'];
        }
        return array_fill(0, $arguments->rowCount(), 1);
    }
}

$conn = (new DuckDB\Database())->connect();
$function = new BrokenScalar();
$conn->registerScalarFunction('php_broken', ['BIGINT'], 'BIGINT', $function);
foreach (['bind', 'init', 'execute'] as $stage) {
    $function->stage = $stage;
    $function->cause = new LogicException("$stage failed");
    $error = fails(fn() => $conn->query('SELECT php_broken(1::BIGINT)'));
    check($error instanceof DuckDB\Exception, "$stage returns a DuckDB exception");
    $cause = $error;
    while ($cause !== null && $cause !== $function->cause) {
        $cause = $cause->getPrevious();
    }
    check($cause === $function->cause, "$stage retains the PHP exception as its cause");
    check($conn->query('SELECT 42')->fetchColumn() === 42, "$stage failure leaves connection reusable");
    echo "$stage exception\n";
}

foreach (['too short', 'too long', 'nonlist', 'wrong type'] as $stage) {
    $function->stage = $stage;
    $error = fails(fn() => $conn->query('SELECT php_broken(1::BIGINT)'));
    check($error instanceof DuckDB\Exception, "$stage output is rejected");
    check($conn->query('SELECT 42')->fetchColumn() === 42, "$stage leaves connection reusable");
    echo "$stage\n";
}
$function->stage = '';
check($conn->query('SELECT php_broken(1::BIGINT)')->fetchColumn() === 1, 'Handler is reusable');

foreach (['', "bad\0name"] as $name) {
    check(fails(fn() => $conn->registerScalarFunction($name, ['BIGINT'], 'BIGINT', $function)) instanceof ValueError,
        'Invalid name rejected');
}
check(fails(fn() => $conn->registerScalarFunction('abs', ['BIGINT'], 'BIGINT', $function)) instanceof Throwable,
    'Built-in function cannot be replaced');
check($conn->query('SELECT abs(-7)')->fetchColumn() === 7, 'Built-in remains usable');
echo "registration validation\n";
?>
--EXPECT--
bind exception
init exception
execute exception
too short
too long
nonlist
wrong type
registration validation
