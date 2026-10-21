<?php

function scalar_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scalar_failure(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }

    throw new RuntimeException('Expected a failure');
}

final class TestScalar implements DuckDB\ScalarFunction
{
    public array $binds = [];
    public int $inits = 0;
    public int $batches = 0;
    public int $rows = 0;
    public array $hooks = [];
    public ?DuckDB\DataChunk $borrowed = null;
    public ?DuckDB\Vector $copied = null;

    public function __construct(public DuckDB\Connection $writer)
    {
    }

    public function bind(array $argumentTypes, array $constantArguments): DuckDB\ScalarFunctionBinding
    {
        $this->binds[] = [$argumentTypes, $constantArguments];
        if (isset($this->hooks['bind'])) {
            ($this->hooks['bind'])();
        }

        return new TestScalarBinding($this);
    }
}

final class TestScalarBinding implements DuckDB\ScalarFunctionBinding
{
    public function __construct(private TestScalar $function)
    {
    }

    public function init(): DuckDB\ScalarFunctionExecutor
    {
        $this->function->inits++;
        if (isset($this->function->hooks['init'])) {
            ($this->function->hooks['init'])();
        }

        return new TestScalarExecutor($this->function);
    }
}

final class TestScalarExecutor implements DuckDB\ScalarFunctionExecutor
{
    public function __construct(private TestScalar $function)
    {
    }

    public function execute(DuckDB\DataChunk $arguments): DuckDB\Vector
    {
        $function = $this->function;
        $function->batches++;
        $function->rows += $arguments->rowCount();
        $function->borrowed = $arguments;
        $function->copied = $arguments->vector(0);
        if (isset($function->hooks['execute'])) {
            return ($function->hooks['execute'])($arguments);
        }

        $values = [];
        foreach ($arguments->toRows(DuckDB\FetchMode::Num) as $row) {
            $values[] = $row[0] === null ? -1 : $row[0] + ($row[1] ?? 0);
        }
        $result = $function->writer->createVector('BIGINT', count($values));
        $result->setValues($function->writer, $values);

        return $result;
    }
}
