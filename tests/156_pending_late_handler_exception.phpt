--TEST--
queryPending: a handler exception raised during an isReady() step is chained to the error await() throws
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

final class FailingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public int $calls = 0;
    public int $failingCall = 0;
    public ?Throwable $cause = null;

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
        $this->calls++;
        if ($this->calls === $this->failingCall) {
            throw $this->cause;
        }

        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

function chained(Throwable $error, Throwable $cause): bool
{
    for ($link = $error; $link !== null; $link = $link->getPrevious()) {
        if ($link === $cause) {
            return true;
        }
    }

    return false;
}

// Each isReady() call runs one slice of the query on the connection's
// helper while this thread services the scalar function. A handler that
// throws in one of those slices fails the query, and the error surfaces
// from a later isReady() or await() call, which must still chain it.
$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$scalar = new FailingScalar();
$conn->registerScalarFunction('php_id', ['BIGINT'], 'BIGINT', $scalar);
$sql = 'SELECT sum(php_id(i)) AS v FROM range(2000000) t(i)';

$paths = [
    'isReady+await' => function () use ($conn, $sql) {
        $pending = $conn->queryPending($sql);
        while (!$pending->isReady()) {
        }
        return $pending->await();
    },
    'await' => fn() => $conn->queryPending($sql)->await(),
    'fiber suspend' => function () use ($conn, $sql) {
        $fiber = new Fiber(fn() => $conn->queryPending($sql)->suspend());
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }
        return $fiber->getReturn();
    },
];

foreach ([1, 5] as $failingCall) {
    foreach ($paths as $name => $run) {
        $scalar->calls = 0;
        $scalar->failingCall = $failingCall;
        $scalar->cause = new LogicException("$name: call $failingCall failed");
        $error = failure($run);
        check($error instanceof DuckDB\Exception, "$name/$failingCall throws a DuckDB exception: " . ($error ? get_class($error) : 'nothing'));
        check(chained($error, $scalar->cause), "$name/$failingCall chains the handler exception: " . $error->getMessage());

        $scalar->failingCall = 0;
        check($conn->query('SELECT php_id(5::BIGINT)')->fetchColumn() === 5, "$name/$failingCall leaves the connection usable");
        echo "$name call $failingCall\n";
    }
}

?>
--EXPECT--
isReady+await call 1
await call 1
fiber suspend call 1
isReady+await call 5
await call 5
fiber suspend call 5
