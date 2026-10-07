--TEST--
Selection vectors: selecting data chunk rows for appending and Arrow export
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

function rejects(callable $operation, string $class): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        check($error->getMessage() !== '', 'Exception must describe the failure');
        return $error->getMessage();
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();
$ids = $conn->createVector('INTEGER', 3);
$ids->setValues($conn, [1, 2, 3]);
$tags = $conn->createVector('VARCHAR[]', 3);
$tags->setValues($conn, [['a'], [], [str_repeat('long ', 8), null]]);
$chunk = DuckDB\DataChunk::fromVectors(['id' => $ids, 'tags' => $tags], 3);

$selected = $chunk->select([2, 0]);
check($selected->rowCount() === 2, 'The row count is the selection length');
check($selected->columns() === $chunk->columns(), 'Columns keep their names and types');
check($selected->toRows() === [
    ['id' => 3, 'tags' => [str_repeat('long ', 8), null]],
    ['id' => 1, 'tags' => ['a']],
], 'Rows follow the selection order');
check($chunk->rowCount() === 3, 'The source chunk is unchanged');

$reused = new DuckDB\SelectionVector([1, 1]);
check($chunk->select($reused)->toRows() === [['id' => 2, 'tags' => []], ['id' => 2, 'tags' => []]],
    'A SelectionVector repeats rows');
check($chunk->vector(0)->select($reused)->toArray() === [2, 2], 'One selection serves chunks and vectors');
echo "select() picks chunk rows\n";

$conn->query('CREATE TABLE items(id INTEGER, tags VARCHAR[])');
$appender = $conn->appender('items');
$appender->appendChunk($selected);
$appender->close();
check($conn->query('SELECT id, tags FROM items ORDER BY id')->fetchAll() === [
    ['id' => 1, 'tags' => ['a']],
    ['id' => 3, 'tags' => [str_repeat('long ', 8), null]],
], 'Appending a selected chunk inserts the selected rows');

$roundtrip = $conn->dataChunkFromArrow($selected->toArrow($conn));
check($selected->arrowSchema($conn)->toArray() === $chunk->arrowSchema($conn)->toArray(), 'Arrow schema matches');
check($roundtrip->toRows() === $selected->toRows(), 'Arrow export round-trips selected rows');
echo "selected chunks append and export to Arrow\n";

$owned = (function (): DuckDB\DataChunk {
    $db = new DuckDB\Database();
    $conn = $db->connect();
    $source = $conn->queryStreaming(
        "SELECT ['x', 'y', 'x', 'y'][i + 1]::ENUM('x', 'y') AS e, {'n': i, 's': 'v' || i} AS s FROM range(4) t(i)"
    );
    $arrow = $source->fetchArrowChunk();
    $imported = $conn->dataChunkFromArrow($arrow);
    $selected = $imported->select([3, 0, 3]);
    unset($imported, $arrow, $source);
    $conn->close();
    return $selected;
})();
gc_collect_cycles();
check($owned->toRows() === [
    ['e' => 'y', 's' => ['n' => 3, 's' => 'v3']],
    ['e' => 'x', 's' => ['n' => 0, 's' => 'v0']],
    ['e' => 'y', 's' => ['n' => 3, 's' => 'v3']],
], 'Dictionary and struct columns imported from Arrow select correctly');
echo "selected Arrow imports own their memory\n";

$empty = $chunk->select([]);
check($empty->rowCount() === 0 && $empty->toRows() === [], 'An empty selection gives an empty chunk');
$appender = $conn->appender('items');
$appender->appendChunk($empty);
$appender->close();
check($conn->query('SELECT count(*) AS n FROM items')->fetchRow() === ['n' => 2], 'Appending no rows is a no-op');
check($empty->toArrow($conn)->rowCount() === 0, 'An empty selected chunk exports to Arrow');
$none = DuckDB\DataChunk::fromVectors(['id' => $ids], 0);
check($none->select([])->rowCount() === 0, 'An empty chunk accepts an empty selection');
$full = $chunk->select(array_fill(0, DuckDB\vectorSize(), 2));
check($full->rowCount() === DuckDB\vectorSize() && $full->vector(0)->get(DuckDB\vectorSize() - 1) === 3,
    'A selection may fill a whole chunk');
echo "empty and full selections are allowed\n";

for ($i = 0; $i < 200; $i++) {
    $loop = $chunk->select([2, 1, 0, 2]);
    $loop->toRows();
    $loop->select([3, 0])->toArrow($conn);
    rejects(fn() => $chunk->select([3]), ValueError::class);
}
echo "repeated chunk selections release memory\n";

$messages = [];
$messages[] = rejects(fn() => $chunk->select([3]), ValueError::class);
$messages[] = rejects(fn() => $none->select([0]), ValueError::class);
$messages[] = rejects(fn() => $chunk->select(array_fill(0, DuckDB\vectorSize() + 1, 0)), ValueError::class);
$messages[] = rejects(fn() => $chunk->select(1), TypeError::class);
$messages[] = rejects(fn() => $chunk->select([1 => 0]), ValueError::class);
$messages[] = rejects(fn() => $chunk->select([0.0]), TypeError::class);
echo implode("\n", $messages), "\n";
?>
--EXPECT--
select() picks chunk rows
selected chunks append and export to Arrow
selected Arrow imports own their memory
empty and full selections are allowed
repeated chunk selections release memory
DuckDB\DataChunk::select(): Argument #1 ($selection) must contain only indices less than 3, 3 given
DuckDB\DataChunk::select(): Argument #1 ($selection) must be empty for an empty source
DuckDB\DataChunk::select(): Argument #1 ($selection) must contain at most 2048 indices
DuckDB\DataChunk::select(): Argument #1 ($selection) must be of type DuckDB\SelectionVector|array, int given
DuckDB\DataChunk::select(): Argument #1 ($selection) must be a list
DuckDB\DataChunk::select(): Argument #1 ($selection) must contain only integers
