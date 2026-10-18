--TEST--
PHP scalar functions: synchronous, streaming and pending paths; worker async rejection and callback guards
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

final class IdentityScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public ?Closure $hook = null;
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
        if ($this->hook !== null) {
            ($this->hook)();
        }
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

$db = new DuckDB\Database();
$conn = $db->connect();
$other = $db->connect();
$function = new IdentityScalar();
$conn->registerScalarFunction('php_identity', ['BIGINT'], 'BIGINT', $function);
$sql = 'SELECT php_identity(i) AS n FROM range(5000) t(i)';
$paths = [
    'query' => fn() => $conn->query($sql),
    'queryStreaming' => fn() => $conn->queryStreaming($sql),
    'execute' => fn() => $conn->execute($sql),
    'prepared' => fn() => $conn->prepare($sql)->execute(),
    'prepared streaming' => fn() => $conn->prepare($sql)->executeStreaming(),
    'pending await' => fn() => $conn->queryPending($sql)->await(),
    'pending polling' => function () use ($conn, $sql) {
        $pending = $conn->queryPending($sql);
        while (!$pending->isReady()) {
        }
        return $pending->await();
    },
];
foreach ($paths as $name => $run) {
    check(array_column($run()->fetchAll(DuckDB\FetchMode::Num), 0) === range(0, 4999),
        "$name processes all batches");
    echo "$name\n";
}
check(fails(fn() => $conn->queryAsync($sql)->await()) instanceof DuckDB\Exception, 'queryAsync rejects PHP');
check(fails(fn() => $conn->prepare($sql)->executeAsync()->await()) instanceof DuckDB\Exception,
    'executeAsync rejects PHP');
check($conn->queryAsync('SELECT 9')->await()->fetchColumn() === 9, 'Unrelated async SQL works');
echo "worker async rejected\n";

$function->hook = function () use ($conn, $other) {
    check(fails(fn() => $conn->query('SELECT 1')) instanceof DuckDB\ConnectionException,
        'Same-connection reentry rejected');
    check($other->query('SELECT 8')->fetchColumn() === 8, 'Ordinary other-connection SQL works');
};
check($conn->query('SELECT php_identity(3::BIGINT)')->fetchColumn() === 3, 'Outer query succeeds');
$function->hook = null;
echo "connection guard\n";

$function->hook = fn() => Fiber::suspend();
$fiber = new Fiber(function () use ($conn) {
    return fails(fn() => $conn->query('SELECT php_identity(4::BIGINT)'));
});
$fiber->start();
check($fiber->isTerminated(), 'Callback did not suspend its Fiber');
check($fiber->getReturn() instanceof DuckDB\Exception, 'Forbidden suspension fails the query');
$function->hook = null;
check($conn->query('SELECT 5')->fetchColumn() === 5, 'Connection recovered after callback failure');
echo "suspension guard\n";
?>
--EXPECT--
query
queryStreaming
execute
prepared
prepared streaming
pending await
pending polling
worker async rejected
connection guard
suspension guard
