<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\DataChunk;
use DuckDB\FetchMode;
use DuckDB\ScalarBinding;
use DuckDB\ScalarExecutor;
use DuckDB\ScalarExpressionFunction;

final class ConstantPrefix implements ScalarExpressionFunction
{
    public function bindExpressions(array $arguments): ScalarBinding
    {
        if ($arguments[1]->getReturnType() !== 'VARCHAR' || !$arguments[1]->isFoldable()) {
            throw new InvalidArgumentException('The prefix must be a constant VARCHAR');
        }

        $prefix = $arguments[1]->fold();
        if ($prefix === null) {
            throw new InvalidArgumentException('The prefix cannot be NULL');
        }

        // Keep the folded PHP value; expression handles expire after binding.
        return new class($prefix) implements ScalarBinding, ScalarExecutor {
            public function __construct(private string $prefix)
            {
            }

            public function init(): ScalarExecutor
            {
                return $this;
            }

            public function execute(DataChunk $arguments): array
            {
                $output = [];
                foreach ($arguments->toRows(FetchMode::Num) as [$label]) {
                    $output[] = $label === null ? null : $this->prefix . $label;
                }

                return $output;
            }
        };
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->registerScalarFunction('constant_prefix', ['VARCHAR', 'VARCHAR'], 'VARCHAR', new ConstantPrefix());
$rows = $conn->query("SELECT constant_prefix(label, 'duck' || ': ')
    FROM (VALUES ('mallard'), ('teal'), (NULL)) labels(label)")->fetchAll(FetchMode::Num);
if ($rows !== [['duck: mallard'], ['duck: teal'], [null]]) {
    throw new RuntimeException('Unexpected prefixed labels');
}
echo json_encode($rows), "\n";

try {
    $conn->query("SELECT constant_prefix(label, label) FROM (VALUES ('teal')) labels(label)");
    throw new RuntimeException('A column prefix must be rejected');
} catch (DuckDB\Exception $error) {
    echo "column prefix rejected\n";
}
