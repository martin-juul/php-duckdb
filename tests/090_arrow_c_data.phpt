--TEST--
Arrow C Data Interface: schema copies, array moves, external batches and release ownership
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

function rejects(callable $operation): void
{
    try {
        $operation();
    } catch (DuckDB\Exception | ValueError $error) {
        return;
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

function release(FFI\CData $value): void
{
    if (!FFI::isNull($value->release)) {
        ($value->release)(FFI::addr($value));
    }
}

$conn = (new DuckDB\Database())->connect();
$result = $conn->query("SELECT 42::INTEGER AS id, [1::INTEGER, NULL] AS values");
$schema = $result->arrowSchema();
$first = $ffi->new('struct ArrowSchema');
$second = $ffi->new('struct ArrowSchema');
$schema->exportToC(address($first));
$schema->exportToC(address($second));
check(!FFI::isNull($first->release) && !FFI::isNull($second->release), 'Schema export releases');
check(FFI::string($first->format) === '+s', 'C schema format');
rejects(fn() => $schema->exportToC(address($first)));
$imported = DuckDB\ArrowSchema::importFromC(address($first));
check(FFI::isNull($first->release), 'Schema import moves ownership');
check($imported->toArray() === $schema->toArray(), 'Imported schema contents');
release($second);
check(FFI::isNull($second->release), 'Copied schema independently released');

$arrow = $result->fetchArrowChunk();
$array = $ffi->new('struct ArrowArray');
$arrow->exportToC(address($array));
check($arrow->isConsumed() && $arrow->rowCount() === 1, 'Array export consumes');
check($array->length === 1 && !FFI::isNull($array->release), 'C array export');
rejects(fn() => $arrow->exportToC(address($array)));
$moved = DuckDB\ArrowChunk::importFromC($imported, address($array));
check(FFI::isNull($array->release), 'Array import moves ownership');
$chunk = $conn->dataChunkFromArrow($moved);
check($chunk->toRows() === [['id' => 42, 'values' => [1, null]]], 'C roundtrip values');
unset($result, $schema, $imported, $arrow, $moved);
gc_collect_cycles();
check($chunk->toRows() === [['id' => 42, 'values' => [1, null]]], 'Roundtrip ownership lifetime');
echo "schemas copy and arrays move through the C Data Interface\n";

// An external producer owns these buffers and its release callback. Its batch
// is larger than DuckDB's usual native vector size and has a nonzero offset.
$schemaReleases = 0;
$arrayReleases = 0;
$root = $ffi->new('struct ArrowSchema');
$field = $ffi->new('struct ArrowSchema');
$fields = $ffi->new('struct ArrowSchema *[1]');
$fields[0] = FFI::addr($field);
$rootFormat = $ffi->new('char[3]');
FFI::memcpy($rootFormat, '+s', 2);
$rootName = $ffi->new('char[1]');
$root->format = FFI::addr($rootFormat[0]);
$root->name = FFI::addr($rootName[0]);
$root->n_children = 1;
$root->children = FFI::addr($fields[0]);
$root->release = static function ($pointer) use (&$schemaReleases): void {
    ++$schemaReleases;
    $pointer->release = null;
};
$fieldFormat = $ffi->new('char[2]');
FFI::memcpy($fieldFormat, 'i', 1);
$fieldName = $ffi->new('char[3]');
FFI::memcpy($fieldName, 'id', 2);
$field->format = FFI::addr($fieldFormat[0]);
$field->name = FFI::addr($fieldName[0]);
$field->flags = 2;
$field->release = static function ($pointer): void {
    $pointer->release = null;
};
$metadataText = pack('l', 2) . pack('l', 4) . 'kind' . pack('l', 8) . 'external'
    . pack('l', 4) . 'kind' . pack('l', 3) . 'two';
$metadata = $ffi->new('char[' . strlen($metadataText) . ']');
FFI::memcpy($metadata, $metadataText, strlen($metadataText));
$root->metadata = FFI::addr($metadata[0]);
$externalSchema = DuckDB\ArrowSchema::importFromC(address($root));
check(FFI::isNull($root->release), 'External schema move');
check($externalSchema->toArray()['metadata'] === [
    ['key' => 'kind', 'value' => 'external'],
    ['key' => 'kind', 'value' => 'two'],
], 'Metadata retains duplicate keys and order');

$external = $ffi->new('struct ArrowArray');
$column = $ffi->new('struct ArrowArray');
$columns = $ffi->new('struct ArrowArray *[1]');
$columns[0] = FFI::addr($column);
$rootBuffers = $ffi->new('const void *[1]');
$buffers = $ffi->new('const void *[2]');
$values = $ffi->new('int[4098]');
for ($i = 0; $i < 4098; ++$i) {
    $values[$i] = $i;
}
$buffers[1] = FFI::addr($values[0]);
$external->length = 4097;
$external->n_buffers = 1;
$external->buffers = FFI::addr($rootBuffers[0]);
$external->n_children = 1;
$external->children = FFI::addr($columns[0]);
$external->offset = 1;
$external->release = static function ($pointer) use (&$arrayReleases): void {
    ++$arrayReleases;
    $pointer->release = null;
};
$column->length = 4098;
$column->n_buffers = 2;
$column->buffers = FFI::addr($buffers[0]);
$column->release = static function ($pointer): void {
    $pointer->release = null;
};
$batch = DuckDB\ArrowChunk::importFromC($externalSchema, address($external));
check(FFI::isNull($external->release), 'External array move');
$native = $conn->dataChunkFromArrow($batch);
$rows = $native->toRows();
check(count($rows) === 4097 && $rows[0] === ['id' => 1] && $rows[4096] === ['id' => 4097], 'Large offset batch');
$conn->query('CREATE TABLE external_copy(id BIGINT)');
$appender = $conn->appender('external_copy');
$appender->appendChunk($native);
$appender->close();
check($conn->query('SELECT count(*) AS n, min(id) AS first, max(id) AS last FROM external_copy')->fetchRow()
    === ['n' => 4097, 'first' => 1, 'last' => 4097], 'Large batch appending');
unset($batch, $externalSchema, $appender, $conn);
gc_collect_cycles();
check($native->toRows()[4096] === ['id' => 4097], 'External buffers survive connection disposal');
unset($native);
gc_collect_cycles();
check($schemaReleases === 1 && $arrayReleases === 1, 'External release callbacks run exactly once');
echo "external metadata, offset batches and release callbacks survive native conversion\n";

$dictionaryConn = (new DuckDB\Database())->connect();
$dictionaryResult = $dictionaryConn->query("SELECT 'green'::ENUM('red', 'green') AS color");
$dictionarySchema = $dictionaryResult->arrowSchema();
check($dictionarySchema->toArray()['children'][0]['dictionary'] !== null, 'Enum dictionary schema');
$dictionaryC = $ffi->new('struct ArrowSchema');
$dictionarySchema->exportToC(address($dictionaryC));
$dictionaryImported = DuckDB\ArrowSchema::importFromC(address($dictionaryC));
check($dictionaryImported->toArray() === $dictionarySchema->toArray(), 'Recursive dictionary schema copy');
$dictionaryArray = $ffi->new('struct ArrowArray');
$dictionaryArrow = $dictionaryResult->fetchArrowChunk();
$dictionaryArrow->exportToC(address($dictionaryArray));
$dictionaryBatch = DuckDB\ArrowChunk::importFromC($dictionaryImported, address($dictionaryArray));
check($dictionaryConn->dataChunkFromArrow($dictionaryBatch)->toRows() === [['color' => 'green']], 'Dictionary data conversion');
unset($dictionaryResult, $dictionarySchema, $dictionaryImported, $dictionaryArrow, $dictionaryBatch, $dictionaryConn);
gc_collect_cycles();
echo "dictionary schemas and arrays roundtrip recursively\n";

// Native schema conversion errors leave the Arrow array available for retry.
$retryConn = (new DuckDB\Database())->connect();
$goodSchema = $chunk->arrowSchema($retryConn);
$badSchemaC = $ffi->new('struct ArrowSchema');
$goodSchema->exportToC(address($badSchemaC));
$badFormat = $ffi->new('char[2]');
FFI::memcpy($badFormat, '?', 1);
$badSchemaC->children[0]->format = FFI::addr($badFormat[0]);
$badSchema = DuckDB\ArrowSchema::importFromC(address($badSchemaC));
$retryArray = $ffi->new('struct ArrowArray');
$chunk->toArrow($retryConn)->exportToC(address($retryArray));
$retryBatch = DuckDB\ArrowChunk::importFromC($badSchema, address($retryArray));
rejects(fn() => $retryConn->dataChunkFromArrow($retryBatch));
check(!$retryBatch->isConsumed(), 'Schema failure must retain input array');
$retryBatch->exportToC(address($retryArray));
$recovered = DuckDB\ArrowChunk::importFromC($goodSchema, address($retryArray));
check($retryConn->dataChunkFromArrow($recovered)->toRows() === $chunk->toRows(), 'Schema recovery retains values');

$emptyArray = $ffi->new('struct ArrowArray');
$chunk->toArrow($retryConn)->exportToC(address($emptyArray));
$emptyArray->length = 0;
$emptyBatch = DuckDB\ArrowChunk::importFromC($goodSchema, address($emptyArray));
$emptyChunk = $retryConn->dataChunkFromArrow($emptyBatch);
check($emptyBatch->isConsumed() && $emptyChunk->rowCount() === 0 && $emptyChunk->toRows() === [], 'Empty external chunk');
check($retryConn->dataChunkFromArrow($emptyChunk->toArrow($retryConn))->toRows() === [], 'Empty native re-export');
echo "schema conversion failures retain arrays and empty batches roundtrip\n";

// A released source must fail before any native conversion can dereference it.
rejects(fn() => DuckDB\ArrowSchema::importFromC(address($root)));
rejects(fn() => DuckDB\ArrowChunk::importFromC($chunk->arrowSchema((new DuckDB\Database())->connect()), address($external)));
rejects(fn() => $chunk->toArrow((new DuckDB\Database())->connect())->exportToC(0));
echo "released inputs and invalid addresses are rejected\n";
?>
--EXPECT--
schemas copy and arrays move through the C Data Interface
external metadata, offset batches and release callbacks survive native conversion
dictionary schemas and arrays roundtrip recursively
schema conversion failures retain arrays and empty batches roundtrip
released inputs and invalid addresses are rejected
