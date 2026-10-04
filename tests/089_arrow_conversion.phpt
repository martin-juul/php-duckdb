--TEST--
Arrow conversion: independent lifetimes, nested values, chunk appending and mixed fetching
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

function rejects(callable $operation): void
{
    try {
        $operation();
    } catch (DuckDB\Exception | ValueError $error) {
        return;
    }

    throw new RuntimeException('Expected rejection');
}

$db = new DuckDB\Database();
$source = $db->connect();
$destination = $db->connect();
$sql = "SELECT i::INTEGER AS id, CASE WHEN i = 2 THEN NULL ELSE 'item-' || i END AS label,
    [i::INTEGER, NULL] AS items, {'number': i::INTEGER, 'text': NULL::VARCHAR} AS detail
    FROM range(3) t(i)";
$expected = $source->query($sql)->fetchAll();
$result = $source->query($sql);
$schema = $result->arrowSchema();
$description = $schema->toArray();
check($description['format'] === '+s', 'Root schema format');
check(array_column($description['children'], 'name') === ['id', 'label', 'items', 'detail'], 'Schema names');
check($description['children'][2]['format'] === '+l', 'List schema');
check(count($description['children'][3]['children']) === 2, 'Struct schema');
check(array_key_exists('metadata', $description) && array_key_exists('dictionary', $description), 'Schema fields');
$arrow = $result->fetchArrowChunk();
check($arrow instanceof DuckDB\ArrowChunk && $arrow->rowCount() === 3, 'Arrow batch');
check(!$arrow->isConsumed(), 'New Arrow batch is available');
check($arrow->schema()->toArray() === $description, 'Batch schema');
check($result->fetchArrowChunk() === null, 'Arrow end of result');
unset($result, $source);
gc_collect_cycles();
$chunk = $destination->dataChunkFromArrow($arrow);
check($arrow->isConsumed() && $arrow->rowCount() === 3, 'Import consumes Arrow but retains row count');
check($chunk->rowCount() === 3 && $chunk->columnCount() === 4, 'Native chunk dimensions');
check(array_column($chunk->columns(), 'name') === ['id', 'label', 'items', 'detail'], 'Native columns');
check($chunk->toRows() === $expected && $chunk->toRows() === $expected, 'Repeated nested conversion');
check($chunk->toRows(DuckDB\FetchMode::Num) === array_map('array_values', $expected), 'Numeric fetch mode');
$both = $chunk->toRows(DuckDB\FetchMode::Both);
check($both[0][0] === 0 && $both[0]['id'] === 0, 'Both fetch mode');
rejects(fn() => $destination->dataChunkFromArrow($arrow));
echo "nested values and schema survive source destruction\n";

$destination->query('CREATE TABLE copied(id INTEGER, label VARCHAR, items INTEGER[], detail STRUCT(number INTEGER, text VARCHAR))');
$appender = $destination->appender('copied');
$appender->appendChunk($chunk);
$appender->appendChunk($chunk);
$converted = $chunk->toArrow($destination);
$appender->appendArrow($converted);
check($converted->isConsumed(), 'Appender consumes Arrow');
$appender->close();
check($destination->query('SELECT * FROM copied ORDER BY id')->fetchAll() === [
    $expected[0], $expected[0], $expected[0],
    $expected[1], $expected[1], $expected[1],
    $expected[2], $expected[2], $expected[2],
], 'Chunk append contents');
check($chunk->arrowSchema($destination)->toArray()['format'] === '+s', 'Native chunk schema');
$again = $chunk->toArrow($destination);
check(!$again->isConsumed(), 'Repeated native export');
check($destination->dataChunkFromArrow($again)->toRows() === $expected, 'Repeated native roundtrip');
unset($appender, $destination, $db);
gc_collect_cycles();
check($chunk->toRows() === $expected, 'Native chunk outlives connections');
echo "native chunks export and append repeatedly across connections\n";

$conn = (new DuckDB\Database())->connect();
$mixed = $conn->query('SELECT i::INTEGER AS i FROM range(4097) t(i)');
check($mixed->fetchRow() === ['i' => 0], 'First row');
rejects(fn() => $mixed->fetchArrowChunk());
check($mixed->fetchRow() === ['i' => 1], 'Failed mixed fetch retains next row');
check(count($mixed->fetchAll()) === 4095, 'Failed mixed fetch retains remaining rows');
$boundary = $conn->query('SELECT i::INTEGER AS i FROM range(4097) t(i)');
$first = $boundary->fetchArrowChunk();
check($boundary->fetchRow() === ['i' => $first->rowCount()], 'Row fetch after Arrow boundary');
$stream = $conn->queryStreaming('SELECT i::INTEGER AS i FROM range(4097) t(i)');
$total = 0;
while (($batch = $stream->fetchArrowChunk()) !== null) {
    $total += $batch->rowCount();
}
check($total === 4097, 'Streaming Arrow batches');
$empty = $conn->query('SELECT 1::INTEGER AS id WHERE false');
check(count($empty->arrowSchema()->toArray()['children']) === 1, 'Empty result schema');
check($empty->fetchArrowChunk() === null, 'Empty result batch');
echo "mixed fetching preserves rows and streaming batches exhaust correctly\n";
?>
--EXPECT--
nested values and schema survive source destruction
native chunks export and append repeatedly across connections
mixed fetching preserves rows and streaming batches exhaust correctly
