--TEST--
Selection vectors: filtering external Arrow batches larger than one vector
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
if (!extension_loaded('FFI')) {
    die('skip FFI extension not loaded');
}
try {
    FFI::cdef('typedef unsigned long long uint64_t;');
} catch (Throwable $error) {
    die('skip FFI is disabled');
}
?>
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

$ffi = FFI::cdef(<<<'C'
typedef long long int64_t;
typedef unsigned long long uintptr_t;
struct ArrowSchema {
    const char *format;
    const char *name;
    const char *metadata;
    int64_t flags;
    int64_t n_children;
    struct ArrowSchema **children;
    struct ArrowSchema *dictionary;
    void (*release)(struct ArrowSchema *);
    void *private_data;
};
struct ArrowArray {
    int64_t length;
    int64_t null_count;
    int64_t offset;
    int64_t n_buffers;
    int64_t n_children;
    const void **buffers;
    struct ArrowArray **children;
    struct ArrowArray *dictionary;
    void (*release)(struct ArrowArray *);
    void *private_data;
};
C);

function address(FFI\CData $value): int
{
    global $ffi;

    return $ffi->cast('uintptr_t', FFI::addr($value))->cdata;
}

function cstring(string $value): FFI\CData
{
    global $ffi;

    $buffer = $ffi->new('char[' . (strlen($value) + 1) . ']');
    FFI::memcpy($buffer, $value, strlen($value));
    $buffer[strlen($value)] = "\0";

    return $buffer;
}

// One external INTEGER column `id` holding 0 .. $rows - 1. The test keeps
// every buffer alive for the whole script; release callbacks only mark.
$rows = 5000;
$root = $ffi->new('struct ArrowSchema');
$field = $ffi->new('struct ArrowSchema');
$fields = $ffi->new('struct ArrowSchema *[1]');
$fields[0] = FFI::addr($field);
$rootFormat = cstring('+s');
$rootName = cstring('');
$fieldFormat = cstring('i');
$fieldName = cstring('id');
$root->format = FFI::addr($rootFormat[0]);
$root->name = FFI::addr($rootName[0]);
$root->n_children = 1;
$root->children = FFI::addr($fields[0]);
$root->release = static function ($pointer): void {
    $pointer->release = null;
};
$field->format = FFI::addr($fieldFormat[0]);
$field->name = FFI::addr($fieldName[0]);
$field->flags = 2;
$field->release = static function ($pointer): void {
    $pointer->release = null;
};
$schema = DuckDB\ArrowSchema::importFromC(address($root));

$external = $ffi->new('struct ArrowArray');
$column = $ffi->new('struct ArrowArray');
$columns = $ffi->new('struct ArrowArray *[1]');
$columns[0] = FFI::addr($column);
$rootBuffers = $ffi->new('const void *[1]');
$buffers = $ffi->new('const void *[2]');
$values = $ffi->new("int[$rows]");
for ($i = 0; $i < $rows; ++$i) {
    $values[$i] = $i;
}
$buffers[1] = FFI::addr($values[0]);
$external->length = $rows;
$external->n_buffers = 1;
$external->buffers = FFI::addr($rootBuffers[0]);
$external->n_children = 1;
$external->children = FFI::addr($columns[0]);
$external->release = static function ($pointer): void {
    $pointer->release = null;
};
$column->length = $rows;
$column->n_buffers = 2;
$column->buffers = FFI::addr($buffers[0]);
$column->release = static function ($pointer): void {
    $pointer->release = null;
};

$conn = (new DuckDB\Database())->connect();
$batch = $conn->dataChunkFromArrow(DuckDB\ArrowChunk::importFromC($schema, address($external)));
check($batch->rowCount() === $rows, 'The external batch is larger than one vector');

$indices = range($rows - DuckDB\vectorSize(), $rows - 1);
$tail = $batch->select(new DuckDB\SelectionVector(array_reverse($indices)));
check($tail->rowCount() === DuckDB\vectorSize(), 'A full selection fits one chunk');
check($tail->vector(0)->get(0) === $rows - 1, 'Indices beyond vectorSize() select the right rows');
check($tail->vector(0)->get(DuckDB\vectorSize() - 1) === $rows - DuckDB\vectorSize(), 'Selection order is kept');
$message = rejects(fn() => $batch->select(range(0, DuckDB\vectorSize())), ValueError::class);
echo "large batches select up to vectorSize() rows\n";

// Filter in PHP, then select and append in vectorSize() batches.
$conn->query('CREATE TABLE evens(id INTEGER)');
$matching = array_keys(array_filter($batch->vector(0)->toArray(), fn(int $id) => $id % 2 === 0));
$appender = $conn->appender('evens');
foreach (array_chunk($matching, DuckDB\vectorSize()) as $part) {
    $appender->appendChunk($batch->select($part));
}
$appender->close();
check($conn->query('SELECT count(*) AS n, min(id) AS first, max(id) AS last FROM evens')->fetchRow()
    === ['n' => 2500, 'first' => 0, 'last' => 4998], 'Batched selections append every matching row');
echo "filtered rows append in batches\n";

// A struct without fields imports as a chunk with rows but no columns.
// DuckDB cannot allocate such a chunk, so selecting from it must throw.
$emptyRoot = $ffi->new('struct ArrowSchema');
$emptyRoot->format = FFI::addr($rootFormat[0]);
$emptyRoot->name = FFI::addr($rootName[0]);
$emptyRoot->release = static function ($pointer): void {
    $pointer->release = null;
};
$emptyArray = $ffi->new('struct ArrowArray');
$emptyArray->length = 3;
$emptyArray->n_buffers = 1;
$emptyArray->buffers = FFI::addr($rootBuffers[0]);
$emptyArray->release = static function ($pointer): void {
    $pointer->release = null;
};
$columnless = $conn->dataChunkFromArrow(
    DuckDB\ArrowChunk::importFromC(DuckDB\ArrowSchema::importFromC(address($emptyRoot)), address($emptyArray))
);
check($columnless->columnCount() === 0 && $columnless->rowCount() === 3, 'A chunk may have rows but no columns');
$columnlessMessage = rejects(fn() => $columnless->select([0, 1]), DuckDB\Exception::class);
echo "chunks without columns reject selection\n";

echo $message, "\n", $columnlessMessage, "\n";
?>
--EXPECT--
large batches select up to vectorSize() rows
filtered rows append in batches
chunks without columns reject selection
DuckDB\DataChunk::select(): Argument #1 ($selection) must contain at most 2048 indices
DuckDB could not allocate the data chunk
