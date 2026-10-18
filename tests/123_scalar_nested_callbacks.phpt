--TEST--
PHP scalar functions: nested scalar and COPY callbacks are rejected across connections
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

final class NestedScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public ?Closure $hook = null;
    public int $executions = 0;
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
        ++$this->executions;
        if ($this->hook !== null) {
            ($this->hook)();
        }
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

$db = new DuckDB\Database();
$outer = $db->connect();
$inner = $db->connect();
$scalar = new NestedScalar();
$nested = new NestedScalar();
$outer->registerScalarFunction('php_outer', ['BIGINT'], 'BIGINT', $scalar);
$inner->registerScalarFunction('php_inner', ['BIGINT'], 'BIGINT', $nested);

$scalar->hook = function () use ($inner) {
    $error = failure(fn() => $inner->query('SELECT php_inner(1::BIGINT)'));
    check($error instanceof DuckDB\Exception, 'Nested scalar rejects PHP reentry');
};
check($outer->query('SELECT php_outer(7::BIGINT)')->fetchColumn() === 7, 'Outer scalar can handle rejection');
check($nested->executions === 0, 'Nested scalar executor did not run');
$scalar->hook = null;
check($inner->query('SELECT php_inner(1::BIGINT)')->fetchColumn() === 1, 'Nested connection recovers');
echo "scalar inside scalar\n";

$dir = copy_scratch();
$format = new LinesFormat();
$inner->registerCopyToFunction('php_lines', $format);
$scalar->hook = function () use ($inner, $dir) {
    $error = failure(fn() => $inner->query("COPY (SELECT 1 AS n) TO '$dir/nested.lines' (FORMAT php_lines)"));
    check($error instanceof DuckDB\Exception, 'COPY inside scalar rejects PHP reentry');
};
check($outer->query('SELECT php_outer(8::BIGINT)')->fetchColumn() === 8, 'Outer scalar handles COPY rejection');
$scalar->hook = null;
check(!in_array('open', $format->log, true), 'Nested COPY writer was never opened');
echo "COPY inside scalar\n";

$format->hooks['write'] = function () use ($outer) {
    $error = failure(fn() => $outer->query('SELECT php_outer(3::BIGINT)'));
    check($error instanceof DuckDB\Exception, 'Scalar inside COPY rejects PHP reentry');
};
$before = $scalar->executions;
$inner->query("COPY (SELECT 2 AS n) TO '$dir/outer.lines' (FORMAT php_lines)");
check($scalar->executions === $before, 'COPY did not execute nested scalar');
check(copy_lines("$dir/outer.lines") === [[2]], 'Outer COPY succeeds after handling rejection');
$format->hooks = [];
check($outer->query('SELECT php_outer(9::BIGINT)')->fetchColumn() === 9, 'Scalar connection recovers');
echo "scalar inside COPY\n";
?>
--EXPECT--
scalar inside scalar
COPY inside scalar
scalar inside COPY
