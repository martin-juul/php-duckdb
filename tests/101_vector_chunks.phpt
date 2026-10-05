--TEST--
Standalone vectors: chunk assembly, column copies, appending and copyFrom()
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

function rejects(callable $operation, string $class = Throwable::class): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        return $error->getMessage();
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();
$ids = $conn->createVector('BIGINT', 3);
$tags = $conn->createVector('VARCHAR[]', 4);
$ids->setValues($conn, [1, 2, 3]);
$tags->setValues($conn, [['a'], [], null, ['unused']]);

$chunk = DuckDB\DataChunk::fromVectors(['id' => $ids, 'tags' => $tags], 3);
check($chunk->rowCount() === 3 && $chunk->columnCount() === 2, 'Chunk dimensions');
check($chunk->columns() === [
    ['name' => 'id', 'type' => 'BIGINT'],
    ['name' => 'tags', 'type' => 'VARCHAR[]'],
], 'Chunk columns');
$rows = [['id' => 1, 'tags' => ['a']], ['id' => 2, 'tags' => []], ['id' => 3, 'tags' => null]];
check($chunk->toRows() === $rows, 'Chunk rows');

$ids->set($conn, 0, 99);
$tags->set($conn, 0, ['changed']);
check($chunk->toRows() === $rows, 'Chunks copy their vectors');
echo "chunks are assembled from vectors\n";

$writer = (new DuckDB\Database())->connect();
$writer->query('CREATE TABLE items(id BIGINT, tags VARCHAR[])');
$appender = $writer->appender('items');
$appender->appendChunk($chunk);
$appender->appendChunk($chunk);
$appender->close();
check($writer->query('SELECT count(*) AS n, sum(id) AS s FROM items')->fetchAll() === [['n' => 6, 's' => 12]],
    'Appended rows');
check($conn->dataChunkFromArrow($chunk->toArrow($conn))->toRows() === $rows, 'Arrow roundtrip');
echo "vector chunks append and export\n";

$column = $chunk->vector(1);
check($column->type() === 'VARCHAR[]' && $column->capacity() === 3, 'Column copy shape');
check($column->toArray() === [['a'], [], null], 'Column copy rows');
$column->set($conn, 1, ['x']);
check($chunk->toRows() === $rows, 'Column copies are independent');

$empty = DuckDB\DataChunk::fromVectors(['id' => $ids], 0);
check($empty->rowCount() === 0 && $empty->toRows() === [] && $empty->vector(0)->capacity() === 0, 'Empty chunk');

$max = $conn->createVector('INTEGER', DuckDB\vectorSize() + 10);
$max->setValues($conn, range(1, DuckDB\vectorSize() + 10));
$full = DuckDB\DataChunk::fromVectors(['n' => $max], DuckDB\vectorSize());
check($full->rowCount() === DuckDB\vectorSize() && $full->vector(0)->get(DuckDB\vectorSize() - 1) === 2048,
    'Full-size chunk');
echo "columns copy out of chunks\n";

$source = $conn->queryStreaming(
    "SELECT ['x', 'y', 'x'][i + 1]::ENUM('x', 'y') AS e, {'n': i, 's': 'v' || i} AS s FROM range(3) t(i)"
);
$imported = $conn->dataChunkFromArrow($source->fetchArrowChunk());
$enums = $imported->vector(0);
$structs = $imported->vector(1);
check($enums->toArray() === ['x', 'y', 'x'], 'Imported dictionary columns flatten');
check($structs->get(2) === ['n' => 2, 's' => 'v2'], 'Imported struct columns copy');
echo "imported Arrow columns are copied flat\n";

$target = $conn->createVector('VARCHAR[]', 5);
$target->copyFrom($tags, 0, 2, 3);
check($target->toArray() === [null, null, null, ['changed'], []], 'Copy into an offset');
$target->copyFrom($tags);
check($target->toArray(0, 4) === [['changed'], [], null, ['unused']], 'Default copy covers the source');
$target->copyFrom($target, 0, 4, 1);
check($target->toArray() === [['changed'], ['changed'], [], null, ['unused']], 'Overlapping self copy');
$target->copyFrom($target, 1, 4, 0);
check($target->toArray(0, 4) === [['changed'], [], null, ['unused']], 'Backward self copy');
$target->copyFrom($tags, 4, 0, 5);
echo "copyFrom() copies ranges\n";

$messages = [
    rejects(fn() => DuckDB\DataChunk::fromVectors([], 0), ValueError::class),
    rejects(fn() => DuckDB\DataChunk::fromVectors([$ids], 1), ValueError::class),
    rejects(fn() => DuckDB\DataChunk::fromVectors(['id' => 1], 1), TypeError::class),
    rejects(fn() => DuckDB\DataChunk::fromVectors(['id' => $ids], 4), ValueError::class),
    rejects(fn() => DuckDB\DataChunk::fromVectors(['id' => $max], DuckDB\vectorSize() + 1), ValueError::class),
    rejects(fn() => DuckDB\DataChunk::fromVectors(['id' => $ids], -1), ValueError::class),
    rejects(fn() => $chunk->vector(2), ValueError::class),
    rejects(fn() => $target->copyFrom($ids), TypeError::class),
    rejects(fn() => $target->copyFrom($tags, 1, 4), ValueError::class),
    rejects(fn() => $target->copyFrom($tags, 0, 4, 2), ValueError::class),
    rejects(fn() => $target->copyFrom($tags, 5), ValueError::class),
    rejects(fn() => $target->copyFrom($tags, 0, -1), ValueError::class),
];
echo implode("\n", $messages), "\n";
?>
--EXPECT--
chunks are assembled from vectors
vector chunks append and export
columns copy out of chunks
imported Arrow columns are copied flat
copyFrom() copies ranges
DuckDB\DataChunk::fromVectors(): Argument #1 ($vectors) must contain at least one vector
DuckDB\DataChunk::fromVectors(): Argument #1 ($vectors) must use column names as keys
DuckDB\DataChunk::fromVectors(): Argument #1 ($vectors) must contain only DuckDB\Vector values
DuckDB\DataChunk::fromVectors(): Argument #1 ($vectors) must contain vectors with a capacity of at least 4, column "id" has 3
DuckDB\DataChunk::fromVectors(): Argument #2 ($rowCount) must be between 0 and 2048
DuckDB\DataChunk::fromVectors(): Argument #2 ($rowCount) must be between 0 and 2048
DuckDB\DataChunk::vector(): Argument #1 ($index) must be between 0 and 1
DuckDB\Vector::copyFrom(): Argument #1 ($source) must have type VARCHAR[], BIGINT given
DuckDB\Vector::copyFrom(): Argument #3 ($count) must leave the range within the vector capacity of 4
DuckDB\Vector::copyFrom(): Argument #4 ($targetOffset) must leave the range within the vector capacity of 5
DuckDB\Vector::copyFrom(): Argument #2 ($sourceOffset) must be between 0 and 4
DuckDB\Vector::copyFrom(): Argument #3 ($count) must be greater than or equal to 0
