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

/** Minimal phase hooks shared by the scalar fatal-error regressions. */
final class TestScalar implements DuckDB\ScalarFunction
{
    public array $hooks = [];

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        if (isset($this->hooks['bind'])) {
            ($this->hooks['bind'])();
        }

        return new TestScalarBinding($this);
    }
}

final class TestScalarBinding implements DuckDB\ScalarBinding
{
    public function __construct(private TestScalar $function)
    {
    }

    public function init(): DuckDB\ScalarExecutor
    {
        if (isset($this->function->hooks['init'])) {
            ($this->function->hooks['init'])();
        }

        return new TestScalarExecutor($this->function);
    }
}

final class TestScalarExecutor implements DuckDB\ScalarExecutor
{
    public function __construct(private TestScalar $function)
    {
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        if (isset($this->function->hooks['execute'])) {
            return ($this->function->hooks['execute'])($arguments);
        }

        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}
