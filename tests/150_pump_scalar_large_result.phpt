--TEST--
Leased pump: a PHP scalar function in a buffered query returns every row of a large result
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php

final class IdentityScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
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
}

// Converting each returned value costs about 2 ms under Memcheck, so the
// harness's Valgrind stage (USE_ZEND_ALLOC=0) checks memory on fewer rows
// that still span many batches.
$rows = getenv('USE_ZEND_ALLOC') === '0' ? 20000 : 200000;
$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$conn->registerScalarFunction('php_id', ['BIGINT'], 'BIGINT', new IdentityScalar());
$values = array_column(
    $conn->query("SELECT php_id(i) AS v FROM range($rows) t(i) ORDER BY v")->fetchAll(DuckDB\FetchMode::Num),
    0
);
echo $values === range(0, $rows - 1) ? "every row\n" : 'rows: ' . count($values) . "\n";
?>
--EXPECT--
every row
