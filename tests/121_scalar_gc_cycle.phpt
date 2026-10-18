--TEST--
PHP scalar functions: registrations that retain their connection are collected
--DESCRIPTION--
Avoid stream writes until cycle collection has finished: the True Async
runtime can stop collecting even ordinary PHP cycles after fwrite().
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
final class CyclicScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public ?DuckDB\Connection $connection = null;
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

$db = new DuckDB\Database();
$outcomes = [];
foreach (['registered only', 'after execution'] as $case) {
    $function = new CyclicScalar();
    $function->connection = $db->connect();
    $function->connection->registerScalarFunction('php_cycle', ['BIGINT'], 'BIGINT', $function);
    if ($case === 'after execution') {
        if ($function->connection->query('SELECT php_cycle(7::BIGINT)')->fetchColumn() !== 7) {
            throw new RuntimeException('Wrong scalar output');
        }
    }
    $watch = WeakReference::create($function);
    unset($function);
    gc_collect_cycles();
    $outcomes[] = $case . ': ' . ($watch->get() === null ? 'collected' : 'still alive');
}
echo implode("\n", $outcomes), "\n";
?>
--EXPECT--
registered only: collected
after execution: collected
