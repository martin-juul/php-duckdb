<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\ArrowChunk;
use DuckDB\ArrowSchema;
use DuckDB\Database;

// C Data Interface pointers are native addresses, not IPC bytes. They must point
// to valid ABI structs for the entire exchange. No external Arrow library is
// needed here: FFI represents the producer/consumer boundary locally.
if (!extension_loaded('FFI')) {
    echo "Arrow C Data Interface example requires PHP FFI; install/enable the FFI extension.\n";
    exit(0);
}
try {
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
} catch (FFI\Exception $error) {
    echo "Arrow C Data Interface example requires enabled FFI; run php -d ffi.enable=true examples/arrow_c_data.php.\n";
    exit(0);
}

function arrowAddress(FFI $ffi, FFI\CData $struct): int
{
    return $ffi->cast('uintptr_t', FFI::addr($struct))->cdata;
}

function checkCArrow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = (new Database())->connect();
$result = $conn->query("SELECT i::INTEGER AS id, CASE WHEN i = 1 THEN NULL ELSE 'row-' || i END AS label FROM range(3) t(i)");
$schema = $result->arrowSchema();
$batch = $result->fetchArrowChunk();
$expected = [['id' => 0, 'label' => 'row-0'], ['id' => 1, 'label' => null], ['id' => 2, 'label' => 'row-2']];

// Empty structs have null release callbacks. Schema export copies ownership;
// array export moves ownership and consumes the PHP ArrowChunk.
$cSchema = $ffi->new('struct ArrowSchema');
$cArray = $ffi->new('struct ArrowArray');
try {
    $schema->exportToC(arrowAddress($ffi, $cSchema));
    $batch->exportToC(arrowAddress($ffi, $cArray));
    checkCArrow($batch->isConsumed() && $cArray->length === 3, 'Array export did not move the batch');
    checkCArrow(count($schema->toArray()['children']) === 2, 'Schema export consumed the original schema');
    unset($result, $batch);

    // An external consumer can inspect the schema and primitive value buffers.
    checkCArrow(FFI::string($cSchema->children[0]->format) === 'i', 'Expected an int32 field');
    $ids = $ffi->cast('int *', $cArray->children[0]->buffers[1]);
    checkCArrow($ids[0] === 0 && $ids[2] === 2, 'External buffer contents changed');

    // Import moves the producer's ownership into PHP. Source release callbacks
    // become null, so the external producer must not release these structs again.
    $importedSchema = ArrowSchema::importFromC(arrowAddress($ffi, $cSchema));
    $importedBatch = ArrowChunk::importFromC($importedSchema, arrowAddress($ffi, $cArray));
    checkCArrow(FFI::isNull($cSchema->release) && FFI::isNull($cArray->release), 'Import did not clear producer ownership');
    checkCArrow($importedBatch->rowCount() === 3 && !$importedBatch->isConsumed(), 'Imported batch is invalid');
    checkCArrow($importedBatch->schema()->toArray() === $importedSchema->toArray(), 'Imported schema mismatch');
    $native = $conn->dataChunkFromArrow($importedBatch);
    checkCArrow($native->toRows() === $expected, 'C Data Interface roundtrip changed rows');
    checkCArrow($importedBatch->isConsumed(), 'Native conversion did not consume the imported batch');
    echo "Arrow C Data Interface roundtrip preserved three rows, including a null string\n";
} finally {
    // On an earlier failure, native structs may still own allocations. Call each
    // live release callback exactly once; imported owners clean up themselves.
    foreach ([$cArray, $cSchema] as $struct) {
        if (!FFI::isNull($struct->release)) {
            ($struct->release)(FFI::addr($struct));
        }
    }
}
