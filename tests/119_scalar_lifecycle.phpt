--TEST--
PHP scalar functions: connection scope, replacement and borrowed batch lifetime
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

final class RetainingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public ?DuckDB\DataChunk $borrowed = null;
    public ?DuckDB\Vector $owned = null;
    public function __construct(private int $offset = 0)
    {
    }
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
        $this->borrowed = $arguments;
        $this->owned = $arguments->vector(0);
        return array_map(fn($v) => $v === null ? null : $v + $this->offset,
            array_column($arguments->toRows(DuckDB\FetchMode::Num), 0));
    }
}

$db = new DuckDB\Database();
$conn = $db->connect();
$function = new RetainingScalar();
$conn->registerScalarFunction('PhpScope', ['BIGINT'], 'BIGINT', $function);
check($conn->query('SELECT PHPSCOPE(11::BIGINT)')->fetchColumn() === 11, 'Names ignore ASCII case');
check(fails(fn() => $db->connect()->query('SELECT phpscope(11::BIGINT)')) instanceof DuckDB\Exception,
    'A different connection cannot execute this handler');
check(fails(fn() => $function->borrowed->toRows()) instanceof Error, 'Retained batch expires');
check($function->owned->toArray() === [11], 'Owned vector copy survives');
echo "connection scope and batch lifetime\n";

$old = $conn->prepare('SELECT phpscope(2::BIGINT)');
$replacement = new RetainingScalar(10);
$conn->registerScalarFunction('phpscope', ['BIGINT'], 'BIGINT', $replacement);
check(fails(fn() => $old->execute()) instanceof DuckDB\Exception, 'Replacement rejects stale prepared SQL');
check($conn->query('SELECT phpscope(2::BIGINT)')->fetchColumn() === 12, 'New query uses replacement');
echo "replacement\n";

$pending = $conn->queryPending('SELECT phpscope(i) FROM range(5000000) t(i)');
$pending->isReady();
unset($pending);
check($conn->query('SELECT 42')->fetchColumn() === 42, 'Discarding pending leaves connection reusable');
echo "pending discarded\n";

$conn->close();
check(fails(fn() => $conn->query('SELECT phpscope(1::BIGINT)')) instanceof DuckDB\ConnectionException,
    'Closed connection rejects execution');
check($function->owned->toArray() === [11], 'Independent vector outlives connection');
echo "connection closed\n";
?>
--EXPECT--
connection scope and batch lifetime
replacement
pending discarded
connection closed
