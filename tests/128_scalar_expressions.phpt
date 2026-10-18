--TEST--
Scalar expressions: resolved types, constant folding, NULLs and callback lifetime
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

final class InspectExpressions implements DuckDB\ScalarExpressionFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public array $expressions = [];
    public array $types = [];
    public array $values = [];
    public array $foldable = [];
    public bool $reject = false;

    public function bindExpressions(array $arguments): DuckDB\ScalarBinding
    {
        check(array_is_list($arguments), 'Arguments must be a list');
        $this->expressions = $arguments;
        $this->types = [];
        $this->values = [];
        $this->foldable = [];
        foreach ($arguments as $expression) {
            check($expression instanceof DuckDB\Expression, 'Expected expression handles');
            $this->types[] = $expression->getReturnType();
            $this->foldable[] = $expression->isFoldable();
            if ($expression->isFoldable()) {
                $this->values[] = $expression->fold();
            } else {
                $error = fails(fn() => $expression->fold());
                check($error instanceof DuckDB\Exception, 'Nonconstant folding must fail');
                check($error->getErrorType() === DuckDB\ErrorType::InvalidInput, 'Nonconstant error category');
                $this->values[] = 'nonconstant';
            }
        }
        if ($this->reject) {
            throw new LogicException('Rejected binding');
        }

        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        return array_fill(0, $arguments->rowCount(), 1);
    }
}

$conn = (new DuckDB\Database())->connect();
$handler = new InspectExpressions();
$types = ['BIGINT', 'VARCHAR', 'DECIMAL(10,2)', 'BLOB', 'INTEGER[]', 'STRUCT(x INTEGER)', 'VARCHAR'];
$conn->registerScalarFunction('php_inspect', $types, 'BIGINT', $handler);
$sql = "SELECT php_inspect(40 + 2, 'd' || 'uck', 12.34::DECIMAL(10,2), from_hex('610062'),
    [1, NULL, 3], {'x': 9}, NULL::VARCHAR)";
check($conn->query($sql)->fetchColumn() === 1, 'Expression function must execute');
$resolved = ['INTEGER', 'VARCHAR', 'DECIMAL(10,2)', 'BLOB', 'INTEGER[]', 'STRUCT("x" INTEGER)', 'VARCHAR'];
check($handler->types === $resolved, 'Resolved SQL argument types');
check($handler->foldable === array_fill(0, 7, true), 'Constants must be foldable');
$expected = $conn->query("SELECT 40 + 2::BIGINT, 'd' || 'uck', 12.34::DECIMAL(10,2),
    from_hex('610062'), [1, NULL, 3], {'x': 9}, NULL::VARCHAR")->fetchRow(DuckDB\FetchMode::Num);
check($handler->values === $expected, 'Folding must follow normal result decoding');
check($handler->values[3] === "a\0b" && $handler->values[6] === null, 'BLOB and typed NULL');
echo "types and constant values\n";

foreach ($handler->expressions as $expression) {
    foreach (['getReturnType', 'isFoldable', 'fold'] as $method) {
        check(fails(fn() => $expression->$method()) instanceof Error, 'Retained handle must expire');
    }
}
$expression = $handler->expressions[0];
check(fails(fn() => clone $expression) instanceof Error, 'Cloning is denied');
check(fails(fn() => serialize($expression)) instanceof Throwable, 'Serialization is denied');
check(fails(fn() => new DuckDB\Expression()) instanceof Error, 'Direct construction is denied');
check(fails(fn() => (new ReflectionClass(DuckDB\Expression::class))->newInstanceWithoutConstructor())
    instanceof ReflectionException, 'Reflection construction is denied');
echo "expired handles and construction\n";

$column = new InspectExpressions();
$conn->registerScalarFunction('php_column', ['BIGINT'], 'BIGINT', $column);
check($conn->query('SELECT sum(php_column(i)) FROM range(3) t(i)')->fetchColumn() === 3,
    'Caught nonconstant fold error must allow execution');
check($column->foldable === [false] && $column->values === ['nonconstant'], 'Column is not foldable');
check($conn->query('SELECT 42')->fetchColumn() === 42, 'Connection recovers');
echo "nonconstant and recovery\n";

$column->reject = true;
$error = fails(fn() => $conn->query('SELECT php_column(42)'));
check($error instanceof DuckDB\Exception, 'Bind rejection must use the extension exception hierarchy');
foreach ($column->expressions as $expression) {
    check(fails(fn() => $expression->fold()) instanceof Error, 'Throwing callbacks expire handles');
}
$column->reject = false;
check($conn->query('SELECT php_column(42)')->fetchColumn() === 1, 'Rejected binding leaves connection reusable');
echo "throwing callback lifetime\n";

final class FailingFold implements DuckDB\ScalarExpressionFunction
{
    public bool $seen = false;

    public function bindExpressions(array $arguments): DuckDB\ScalarBinding
    {
        try {
            $arguments[0]->fold();
        } catch (DuckDB\ConversionException $error) {
            check(str_starts_with($error->getMessage(), 'Conversion Error:'),
                'Native folding errors must have readable classified messages');
            $this->seen = true;
            throw $error;
        }
        throw new LogicException('Expected constant folding to fail');
    }
}

$foldError = new FailingFold();
$conn->registerScalarFunction('php_bad_fold', ['INTEGER'], 'INTEGER', $foldError);
check(fails(fn() => $conn->query("SELECT php_bad_fold(CAST('bad' AS INTEGER))")) instanceof DuckDB\Exception,
    'Native folding errors must reject binding');
check($foldError->seen, 'Native folding error was handled in the callback');
echo "native folding error\n";

$zero = new InspectExpressions();
$conn->registerScalarFunction('php_expression_zero', [], 'BIGINT', $zero);
check($conn->query('SELECT php_expression_zero()')->fetchColumn() === 1, 'Zero argument execution');
check($zero->expressions === [], 'Zero arguments must be an empty list');
echo "zero arguments\n";
?>
--EXPECT--
types and constant values
expired handles and construction
nonconstant and recovery
throwing callback lifetime
native folding error
zero arguments
