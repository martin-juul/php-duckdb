<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\DataChunk;
use DuckDB\FetchMode;
use DuckDB\ScalarBinding;
use DuckDB\ScalarExecutor;
use DuckDB\ScalarFunction;

final class NormalizeLabel implements ScalarFunction
{
    public function bind(array $argumentTypes): ScalarBinding
    {
        if ($argumentTypes !== ['VARCHAR']) {
            throw new InvalidArgumentException('normalize_label requires VARCHAR');
        }

        return new LabelBinding();
    }
}

final class LabelBinding implements ScalarBinding
{
    public function init(): ScalarExecutor
    {
        // DuckDB initializes separate executor state for each executing worker.
        return new LabelExecutor();
    }
}

final class LabelExecutor implements ScalarExecutor
{
    public function execute(DataChunk $arguments): array
    {
        $output = [];
        foreach ($arguments->toRows(FetchMode::Num) as [$label]) {
            // Special NULL handling passes every row to PHP.
            $output[] = $label === null ? '(missing)' : strtolower(trim($label));
        }

        return $output;
    }
}

$conn = (new DuckDB\Database())->connect();
$conn->registerScalarFunction('normalize_label', ['VARCHAR'], 'VARCHAR', new NormalizeLabel());
$conn->query("CREATE TABLE labels(label VARCHAR);
    INSERT INTO labels VALUES ('  DUCK  '), (NULL), (' Goose ')");
$actual = $conn->query('SELECT normalize_label(label) AS label FROM labels')->fetchAll(FetchMode::Num);
$expected = [['duck'], ['(missing)'], ['goose']];
if ($actual !== $expected) {
    throw new RuntimeException('Unexpected normalized labels: ' . json_encode($actual));
}
echo json_encode($actual), "\n";

$statement = $conn->prepare('SELECT normalize_label(?::VARCHAR) AS label');
foreach (['  HERON ', 'TERN'] as $label) {
    echo $statement->execute([$label])->fetchColumn(), "\n";
}

// Pending execution drives callbacks on this request thread as it progresses.
$pending = $conn->queryPending('SELECT count(*) FROM labels WHERE normalize_label(label) <> \'(missing)\'');
if ($pending->await()->fetchColumn() !== 2) {
    throw new RuntimeException('Expected two nonmissing labels');
}
echo "pending query: 2 labels\n";
