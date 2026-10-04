--TEST--
Arrow C Data Interface slices preserve nested struct validity and list/array descendants
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
$sql = <<<'SQL'
SELECT i::INTEGER AS id,
       {'payload': {'value': i::INTEGER}} AS deep,
       CASE WHEN i % 2 = 0
            THEN NULL::STRUCT(payload STRUCT(value INTEGER))
            ELSE {'payload': {'value': i::INTEGER}} END AS nullable,
       [{'detail': {'value': i::INTEGER}},
        {'detail': {'value': (i + 100)::INTEGER}}] AS items,
       [{'detail': {'value': i::INTEGER}},
        {'detail': {'value': (i + 100)::INTEGER}}]
            ::STRUCT(detail STRUCT(value INTEGER))[2] AS fixed
FROM range(8) t(i)
SQL;
$expected = array_slice($conn->query($sql)->fetchAll(), 3, 3);
$result = $conn->query($sql);
$schema = $result->arrowSchema();
$batch = $result->fetchArrowChunk();
$array = $ffi->new('struct ArrowArray');
$batch->exportToC(address($array));
$array->offset = 3;
$array->length = 3;
$imported = DuckDB\ArrowChunk::importFromC($schema, address($array));
$native = $conn->dataChunkFromArrow($imported);
check($native->toRows() === $expected, 'Nested slices and nullable struct rows');
check($array->children[1]->offset === 0 && $array->children[1]->length === 8,
    'Producer structs retain their original offsets and lengths');
check($conn->dataChunkFromArrow($native->toArrow($conn))->toRows() === $expected,
    'Normalized nested slice exports correctly');
unset($result, $batch, $imported, $schema);
gc_collect_cycles();
check($native->toRows() === $expected, 'Normalized view owns validity buffers and producer lifetime');
echo "root slices preserve deeply nested structs, nulls, lists and fixed arrays\n";

$result = $conn->query("SELECT {'payload': {'value': i::INTEGER}} AS deep FROM range(8) t(i)");
$schema = $result->arrowSchema();
$batch = $result->fetchArrowChunk();
$array = $ffi->new('struct ArrowArray');
$batch->exportToC(address($array));
$array->offset = 1;
$array->length = 3;
$array->children[0]->offset = 2;
$array->children[0]->length = 6;
$native = $conn->dataChunkFromArrow(DuckDB\ArrowChunk::importFromC($schema, address($array)));
check($native->toRows() === [
    ['deep' => ['payload' => ['value' => 3]]],
    ['deep' => ['payload' => ['value' => 4]]],
    ['deep' => ['payload' => ['value' => 5]]],
], 'Root and nested struct offsets accumulate');
echo "independent parent and child struct offsets accumulate correctly\n";
?>
--EXPECT--
root slices preserve deeply nested structs, nulls, lists and fixed arrays
independent parent and child struct offsets accumulate correctly
