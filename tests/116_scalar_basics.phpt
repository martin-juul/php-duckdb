--TEST--
PHP scalar functions: binding, worker state, batches, NULLs and prepared reuse
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

final class IncrementFunction implements DuckDB\ScalarFunction
{
    public array $types = [];
    public int $initializers = 0;
    public int $batches = 0;
    public int $rows = 0;

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        $this->types[] = $argumentTypes;
        return new class($this) implements DuckDB\ScalarBinding {
            public function __construct(private IncrementFunction $owner)
            {
            }

            public function init(): DuckDB\ScalarExecutor
            {
                ++$this->owner->initializers;
                return new class($this->owner) implements DuckDB\ScalarExecutor {
                    public function __construct(private IncrementFunction $owner)
                    {
                    }

                    public function execute(DuckDB\DataChunk $arguments): array
                    {
                        ++$this->owner->batches;
                        $this->owner->rows += $arguments->rowCount();
                        $out = [];
                        foreach ($arguments->toRows(DuckDB\FetchMode::Num) as [$value]) {
                            $out[] = $value === null ? -1 : $value + 1;
                        }
                        return $out;
                    }
                };
            }
        };
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$function = new IncrementFunction();
$conn->registerScalarFunction('php_increment', ['BIGINT'], 'BIGINT', $function);
$rows = $conn->query('SELECT php_increment(i) FROM range(5000) t(i)')->fetchAll(DuckDB\FetchMode::Num);
check(array_column($rows, 0) === range(1, 5000), 'Every row in every batch is transformed');
check($function->types !== [] && $function->types[0] === ['BIGINT'], 'bind sees resolved types');
check($function->initializers >= 1 && $function->batches >= 3 && $function->rows === 5000,
    'Worker initialization precedes complete batch execution');
echo "5000 rows, bound types and worker state\n";

check($conn->query('SELECT php_increment(NULL::BIGINT)')->fetchColumn() === -1,
    'Special NULL handling calls PHP for NULL');
echo "NULL input\n";

$statement = $conn->prepare('SELECT php_increment(?::BIGINT)');
foreach ([5, 8, null] as $input) {
    check($statement->execute([$input])->fetchColumn() === ($input === null ? -1 : $input + 1),
        'Prepared execution uses its input');
}
echo "prepared reuse\n";

$zero = new class implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor {
    public int $rows = 0;
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        check($argumentTypes === [], 'A zero-argument function has no argument types');
        return $this;
    }
    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }
    public function execute(DuckDB\DataChunk $arguments): array
    {
        check($arguments->columnCount() === 0, 'The input has no columns');
        $this->rows += $arguments->rowCount();
        return array_fill(0, $arguments->rowCount(), 7);
    }
};
$conn->registerScalarFunction('php_seven', [], 'BIGINT', $zero);
check($conn->query('SELECT sum(php_seven()) FROM range(5000)')->fetchColumn() === 35000,
    'A zero-argument function returns one value per row');
check($zero->rows === 5000, 'Volatile functions are not folded to one constant invocation');
echo "zero arguments and volatility\n";
?>
--EXPECT--
5000 rows, bound types and worker state
NULL input
prepared reuse
zero arguments and volatility
