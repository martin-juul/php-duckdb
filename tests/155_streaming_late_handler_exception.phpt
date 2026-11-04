--TEST--
Pumped streaming: a handler exception that DuckDB reports on a later fetch is still chained
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--ENV--
DUCKDB_PHP_TEST_LEASE_LINGER_MS=20
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

// DuckDB workers hand their callbacks to this thread, also after a call's
// own DuckDB work is done: a fetch that restarts them returns a buffered
// chunk while they still run. The test switch keeps each call servicing
// callbacks for a while after its DuckDB work, as a request thread that
// wakes late does, so a failing callback deterministically runs in a call
// that succeeds and DuckDB reports its failure on a later fetch.
$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$conn->query("SET streaming_buffer_size = '64KB'");
$scalar = new FailingScalar();
$conn->registerScalarFunction('php_id', ['BIGINT'], 'BIGINT', $scalar);
$rows = 200000;
$sql = "SELECT php_id(i) AS v FROM range($rows) t(i)";

$paths = [
    'foreach' => function () use ($conn, $sql) {
        $read = 0;
        foreach ($conn->queryStreaming($sql) as $row) {
            $read++;
        }
        return $read;
    },
    'fetchRow' => function () use ($conn, $sql) {
        $result = $conn->queryStreaming($sql);
        $read = 0;
        while ($result->fetchRow() !== null) {
            $read++;
        }
        return $read;
    },
    'fetchAll' => fn() => count($conn->queryStreaming($sql)->fetchAll()),
    'fetchColumn' => function () use ($conn, $sql) {
        $result = $conn->queryStreaming($sql);
        $read = 0;
        while ($result->fetchColumn() !== null) {
            $read++;
        }
        return $read;
    },
    'fetchArrowChunk' => function () use ($conn, $sql) {
        $result = $conn->queryStreaming($sql);
        $read = 0;
        while (($chunk = $result->fetchArrowChunk()) !== null) {
            $read += $chunk->rowCount();
        }
        return $read;
    },
    'executeStreaming' => function () use ($conn, $sql) {
        $read = 0;
        foreach ($conn->prepare($sql)->executeStreaming() as $row) {
            $read++;
        }
        return $read;
    },
];

// Call 2 fails while the statement starts; call 10 while a later fetch runs.
foreach ([2, 10] as $failingCall) {
    foreach ($paths as $name => $read) {
        $scalar->calls = 0;
        $scalar->failingCall = $failingCall;
        $scalar->cause = new LogicException("$name: call $failingCall failed");
        $error = failure($read);
        check($error instanceof DuckDB\Exception, "$name/$failingCall throws a DuckDB exception: " . get_class($error));
        $link = $error;
        while ($link !== null && $link !== $scalar->cause) {
            $link = $link->getPrevious();
        }
        check($link === $scalar->cause, "$name/$failingCall chains the handler exception: " . $error->getMessage());
        check(str_contains($error->getMessage(), "call $failingCall failed"), "$name/$failingCall message: " . $error->getMessage());

        $scalar->failingCall = 0;
        check($conn->query('SELECT php_id(5::BIGINT)')->fetchColumn() === 5, "$name/$failingCall leaves the connection usable");
        echo "$name call $failingCall\n";
    }
}
?>
--EXPECT--
foreach call 2
fetchRow call 2
fetchAll call 2
fetchColumn call 2
fetchArrowChunk call 2
executeStreaming call 2
foreach call 10
fetchRow call 10
fetchAll call 10
fetchColumn call 10
fetchArrowChunk call 10
executeStreaming call 10
