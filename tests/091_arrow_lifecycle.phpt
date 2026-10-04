--TEST--
Arrow lifecycle: guarded objects, failed appends, closed connections and streaming boundaries
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

function rejects(callable $operation, string $class = Throwable::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected exception: ' . $error->getMessage());
        check($error->getMessage() !== '', 'Exception must describe the failure');
        return;
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();
$result = $conn->query('SELECT 7::INTEGER AS id');
$schema = $result->arrowSchema();
$arrow = $result->fetchArrowChunk();
$chunk = $conn->dataChunkFromArrow($arrow);
$objects = [$schema, $arrow, $chunk];
foreach ($objects as $object) {
    rejects(fn() => clone $object, Error::class);
    rejects(fn() => serialize($object), Exception::class);
    $class = get_class($object);
    rejects(fn() => unserialize('O:' . strlen($class) . ':"' . $class . '":0:{}'), Exception::class);
    rejects(fn() => new $class(), Error::class);
    $reflection = new ReflectionClass($class);
    try {
        $uninitialized = $reflection->newInstanceWithoutConstructor();
    } catch (ReflectionException $error) {
        continue;
    }
    $method = $object instanceof DuckDB\ArrowSchema ? 'toArray' : 'rowCount';
    rejects(fn() => $uninitialized->$method(), Error::class);
}
check($chunk->toRows() === [['id' => 7]], 'Guards preserve valid native chunks');
check($arrow->isConsumed() && $arrow->schema()->toArray() === $schema->toArray(), 'Consumed batch keeps schema');
rejects(fn() => $conn->dataChunkFromArrow($arrow), DuckDB\Exception::class);
rejects(fn() => $arrow->exportToC(0));
echo "owning objects reject cloning, serialization and invalid construction\n";

$available = $chunk->toArrow($conn);
$closed = (new DuckDB\Database())->connect();
$closed->close();
rejects(fn() => $closed->dataChunkFromArrow($available), DuckDB\ConnectionException::class);
check(!$available->isConsumed(), 'Closed import must retain available batch');
rejects(fn() => $chunk->toArrow($closed), DuckDB\ConnectionException::class);
rejects(fn() => $chunk->arrowSchema($closed), DuckDB\ConnectionException::class);
check($conn->dataChunkFromArrow($available)->toRows() === [['id' => 7]], 'Retry import with live connection');
echo "closed connection conversion failures preserve source ownership\n";

// Materialized results must retain the conversion context before their first export.
foreach ([false, true] as $async) {
    $orphanDb = new DuckDB\Database();
    $orphanConn = $orphanDb->connect();
    $sql = "SELECT 11::INTEGER AS id, ['alive', NULL] AS labels";
    $orphan = $async ? $orphanConn->queryAsync($sql)->await() : $orphanConn->query($sql);
    $orphanConn->close();
    unset($orphanConn, $orphanDb);
    gc_collect_cycles();
    $orphanSchema = $orphan->arrowSchema();
    check(array_column($orphanSchema->toArray()['children'], 'name') === ['id', 'labels'], 'Orphan result schema');
    $orphanArrow = $orphan->fetchArrowChunk();
    check($conn->dataChunkFromArrow($orphanArrow)->toRows() === [['id' => 11, 'labels' => ['alive', null]]], 'Orphan result converts after connection release');
    check($orphan->fetchArrowChunk() === null, 'Orphan result exhausts normally');
    unset($orphan, $orphanArrow, $orphanSchema);
}
echo "materialized and async results export after source connection release\n";

$conn->query('CREATE TABLE target(id INTEGER)');
$app = $conn->appender('target');
$available = $chunk->toArrow($conn);
$app->beginRow();
$app->append(3);
rejects(fn() => $app->appendArrow($available), Error::class);
check(!$available->isConsumed(), 'Open row rejection must retain batch');
$app->endRow();
$app->appendArrow($available);
check($available->isConsumed(), 'Successful append consumes batch');
$app->flush();
$bad = $conn->query("SELECT 'bad'::VARCHAR AS id")->fetchArrowChunk();
rejects(fn() => $app->appendArrow($bad), DuckDB\Exception::class);
check($bad->isConsumed(), 'Native append failure follows conversion consumption');
$retry = $chunk->toArrow($conn);
rejects(fn() => $app->appendArrow($retry), DuckDB\Exception::class);
check(!$retry->isConsumed(), 'Failed appender rejects before conversion');
$app->clear();
$app->appendArrow($retry);
$app->close();
check($conn->query('SELECT id FROM target ORDER BY id')->fetchAll() === [['id' => 3], ['id' => 7], ['id' => 7]], 'Clear recovers without replaying flushed rows');
$afterClose = $chunk->toArrow($conn);
rejects(fn() => $app->appendArrow($afterClose), DuckDB\Exception::class);
check(!$afterClose->isConsumed(), 'Closed appender retains batch');
echo "appender state checks preserve batches and clear recovers failed submission\n";

$stream = $conn->queryStreaming('SELECT i::INTEGER AS i FROM range(10000) t(i)');
$first = $stream->fetchArrowChunk();
$conn->query('SELECT 1');
rejects(fn() => $stream->fetchArrowChunk(), DuckDB\Exception::class);
$other = (new DuckDB\Database())->connect();
check($other->dataChunkFromArrow($first)->toRows()[0] === ['i' => 0], 'Exported batch survives stream invalidation');
$rows = $conn->query('SELECT i::INTEGER AS i FROM range(8193) t(i)');
$first = $rows->fetchArrowChunk();
$position = $first->rowCount();
check($rows->fetchRow() === ['i' => $position], 'First row after whole Arrow batch');
rejects(fn() => $rows->fetchArrowChunk(), DuckDB\Exception::class);
check($rows->fetchRow() === ['i' => $position + 1], 'Mixed rejection retains row position');
$tail = $rows->fetchAll();
check(count($tail) === 8193 - $position - 2 && end($tail) === ['i' => 8192], 'All remaining chunks retain their rows');
check($rows->fetchArrowChunk() === null && $rows->fetchRow() === null, 'Mixed fetch exhaustion remains stable');
$empty = $conn->queryStreaming('SELECT 1::INTEGER AS id WHERE false');
check(count($empty->arrowSchema()->toArray()['children']) === 1, 'Empty stream has schema');
check($empty->fetchArrowChunk() === null && $empty->fetchArrowChunk() === null, 'Empty stream has no batches');
echo "exported batches survive invalidation and mixed fetches preserve stream position\n";
?>
--EXPECT--
owning objects reject cloning, serialization and invalid construction
closed connection conversion failures preserve source ownership
materialized and async results export after source connection release
appender state checks preserve batches and clear recovers failed submission
exported batches survive invalidation and mixed fetches preserve stream position
