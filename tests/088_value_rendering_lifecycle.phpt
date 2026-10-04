--TEST--
Value string rendering: snapshots, connection-local resolution, errors and stream lifecycle
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
declare(strict_types=1);

function renderingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renderingReject(callable $operation, string $exception): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        renderingAssert($error instanceof $exception, 'Unexpected exception: ' . get_class($error));
        return;
    }
    throw new RuntimeException('Rendering unexpectedly succeeded');
}

$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 1');
$number = 1;
$input = [&$number];
$value = new DuckDB\ListValue($input, DuckDB\Integer::class);
$number = 9;
$input[] = 10;
renderingAssert($value->toString($conn) === '[1]', 'Array snapshot changed');
$date = new DateTime('2026-01-01 12:00:00.123456+02:00');
$timestamp = new DuckDB\Timestamp($date);
$date->modify('+1 year');
renderingAssert($timestamp->toString($conn) === '2026-01-01 10:00:00.123456', 'DateTime snapshot changed');
$interval = new DuckDB\Interval(1, 2, 3);
$intervalValue = new DuckDB\IntervalValue($interval);
$interval->__construct(9, 9, 9);
renderingAssert($intervalValue->toString($conn) === '1 month 2 days 00:00:00.000003', 'Interval snapshot changed');
renderingAssert((new ReflectionMethod(DuckDB\Value::class, 'toString'))->isFinal(), 'Renderer is overridable');
renderingAssert(!method_exists(DuckDB\Value::class, '__toString'), 'Implicit conversion requires a hidden connection');
renderingReject(fn() => serialize($value), Exception::class);
echo "snapshots and explicit final renderer: ok\n";

$conn->query('CREATE TYPE amount AS DECIMAL(10,2)');
$other = (new DuckDB\Database())->connect();
$other->query('SET threads = 1');
$other->query('CREATE TYPE amount AS DECIMAL(12,3)');
$amount = new DuckDB\CatalogValue('1.235', 'amount');
renderingAssert($amount->toString($conn) === '1.24', 'First catalog resolution failed');
renderingAssert($amount->toString($other) === '1.235', 'Second catalog resolution reused stale metadata');
renderingAssert($amount->toString($conn) === '1.24', 'Connection reuse changed the snapshot');
$conn->query('DROP TYPE amount');
$conn->query('CREATE TYPE amount AS DECIMAL(12,3)');
renderingAssert($amount->toString($conn) === '1.235', 'Catalog replacement reused stale metadata');
$conn->beginTransaction();
$conn->query("CREATE TYPE local_mood AS ENUM('happy','sad')");
$mood = new DuckDB\CatalogValue('happy', 'local_mood');
renderingAssert($mood->toString($conn) === 'happy', 'Transaction-local type was not visible');
$conn->rollBack();
renderingReject(fn() => $mood->toString($conn), DuckDB\Exception::class);
renderingReject(fn() => $mood->toString($other), DuckDB\Exception::class);
echo "catalog changes, transactions and connection reuse: ok\n";

$zoned = new DuckDB\TimestampTz('2026-01-01 12:00:00+00');
$conn->query("SET TimeZone = 'UTC'");
renderingAssert($zoned->toString($conn) === '2026-01-01 12:00:00+00', 'UTC rendering failed');
$conn->query("SET TimeZone = 'Europe/Copenhagen'");
renderingAssert($zoned->toString($conn) === '2026-01-01 13:00:00+01', 'Timezone setting was not used');
$conn->query("SET TimeZone = 'UTC'");
renderingAssert($zoned->toString($conn) === '2026-01-01 12:00:00+00', 'Timezone reuse cached rendered text');
echo "session timezone presentation: ok\n";

$bad = new DuckDB\Integer('not an integer');
$shape = new DuckDB\Value('STRUCT(a INTEGER)', ['wrong' => 1]);
$uninitialized = (new ReflectionClass(DuckDB\Value::class))->newInstanceWithoutConstructor();
renderingReject(fn() => $bad->toString($conn), DuckDB\Exception::class);
renderingReject(fn() => $shape->toString($conn), ValueError::class);
renderingReject(fn() => $uninitialized->toString($conn), Error::class);
// Internal final connections cannot reach an uninitialized public state.
renderingReject(fn() => (new ReflectionClass(DuckDB\Connection::class))->newInstanceWithoutConstructor(), ReflectionException::class);
renderingReject(fn() => $value->toString(new stdClass()), TypeError::class);
$closed = (new DuckDB\Database())->connect();
$closed->close();
renderingReject(fn() => $value->toString($closed), DuckDB\ConnectionException::class);
for ($iteration = 0; $iteration < 10; $iteration++) {
    renderingReject(fn() => $bad->toString($conn), DuckDB\Exception::class);
    renderingAssert((new DuckDB\Decimal('12.345', 18, 2))->toString($conn) === '12.35', 'Error poisoned next rendering');
}
echo "errors and repeated recovery: ok\n";

foreach ([false, true] as $fail) {
    $stream = $conn->queryStreaming('SELECT i FROM range(5000) t(i)');
    renderingAssert($stream->fetchRow()['i'] === 0, 'Stream failed before rendering');
    if ($fail) {
        renderingReject(fn() => $bad->toString($conn), DuckDB\Exception::class);
    } else {
        renderingAssert($value->toString($conn) === '[1]', 'Stream-side rendering failed');
    }
    renderingReject(fn() => $stream->fetchAll(), DuckDB\Exception::class);
    unset($stream);
}
$stream = $other->queryStreaming('SELECT i FROM range(5000) t(i)');
$value->toString($conn);
renderingAssert(count($stream->fetchAll()) === 5000, 'Rendering invalidated another connection');
$pending = $conn->queryAsync('SELECT sum(i)::BIGINT AS total FROM range(10000) t(i)');
renderingAssert($value->toString($conn) === '[1]', 'Rendering during async execution failed');
renderingAssert($pending->await()->fetchRow()['total'] === 49995000, 'Async result was changed');
echo "stream invalidation, connection isolation and async ownership: ok\n";
?>
--EXPECT--
snapshots and explicit final renderer: ok
catalog changes, transactions and connection reuse: ok
session timezone presentation: ok
errors and repeated recovery: ok
stream invalidation, connection isolation and async ownership: ok
