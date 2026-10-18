--TEST--
Scalar expressions: prepared rebinding and legacy type-string callbacks
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

final class BoundConstant implements DuckDB\ScalarExpressionFunction
{
    public array $foldable = [];

    public function bindExpressions(array $arguments): DuckDB\ScalarBinding
    {
        check(count($arguments) === 1, 'Expected one argument');
        check($arguments[0]->getReturnType() === 'BIGINT', 'Expected resolved parameter type');
        $foldable = $arguments[0]->isFoldable();
        $this->foldable[] = $foldable;
        $value = $foldable ? $arguments[0]->fold() : null;

        return new class($foldable, $value) implements DuckDB\ScalarBinding, DuckDB\ScalarExecutor {
            public function __construct(private bool $foldable, private mixed $value)
            {
            }

            public function init(): DuckDB\ScalarExecutor
            {
                return $this;
            }

            public function execute(DuckDB\DataChunk $arguments): array
            {
                if ($this->foldable) {
                    return array_fill(0, $arguments->rowCount(), $this->value);
                }

                return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
            }
        };
    }
}

$conn = (new DuckDB\Database())->connect();
$handler = new BoundConstant();
$conn->registerScalarFunction('php_bound_constant', ['BIGINT'], 'BIGINT', $handler);
$statement = $conn->prepare('SELECT php_bound_constant(?::BIGINT)');
foreach ([17, 91, null, -4] as $value) {
    check($statement->execute([$value])->fetchColumn() === $value, 'Prepared execution must use current input');
}
check($handler->foldable[0] === false, 'Unresolved prepared parameter is not foldable');
echo "prepared rebinding\n";

$legacy = new class implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor {
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        check($argumentTypes === ['INTEGER'], 'Legacy callback must still receive type strings');
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
};
$conn->registerScalarFunction('php_legacy_expression', ['BIGINT'], 'BIGINT', $legacy);
check($conn->query('SELECT php_legacy_expression(23)')->fetchColumn() === 23, 'Legacy scalar execution');
echo "legacy callbacks\n";
?>
--EXPECT--
prepared rebinding
legacy callbacks
