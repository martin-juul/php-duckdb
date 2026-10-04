--TEST--
GeoArrow external metadata: preserve unknown CRS definitions and reject invalid metadata without losing ownership
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

function externalGeometry(DuckDB\Connection $conn, string $json, int &$schemaReleases, int &$arrayReleases): array
{
    global $ffi;

    $result = $conn->query("SELECT 'POINT (1 2)'::GEOMETRY AS point");
    $originalSchema = $ffi->new('struct ArrowSchema');
    $originalArray = $ffi->new('struct ArrowArray');
    $result->arrowSchema()->exportToC(address($originalSchema));
    $result->fetchArrowChunk()->exportToC(address($originalArray));

    $pairs = [
        'ARROW:extension:name' => 'geoarrow.wkb',
        'ARROW:extension:metadata' => $json,
    ];
    $text = pack('l', count($pairs));
    foreach ($pairs as $key => $value) {
        $text .= pack('l', strlen($key)) . $key . pack('l', strlen($value)) . $value;
    }
    $metadata = $ffi->new('char[' . strlen($text) . ']');
    FFI::memcpy($metadata, $text, strlen($text));
    $originalSchema->children[0]->metadata = FFI::addr($metadata[0]);

    // Keep the original callbacks untouched; facade callbacks track when their
    // native allocations are released, including failures before consumption.
    $schemaFacade = $ffi->new('struct ArrowSchema');
    FFI::memcpy(FFI::addr($schemaFacade), FFI::addr($originalSchema), FFI::sizeof($originalSchema));
    $schemaCallback = static function ($pointer) use ($originalSchema, $metadata, &$schemaReleases): void {
        ++$schemaReleases;
        $pointer->release = null;
        release($originalSchema);
    };
    $schemaFacade->release = $schemaCallback;
    $arrayFacade = $ffi->new('struct ArrowArray');
    FFI::memcpy(FFI::addr($arrayFacade), FFI::addr($originalArray), FFI::sizeof($originalArray));
    $arrayCallback = static function ($pointer) use ($originalArray, &$arrayReleases): void {
        ++$arrayReleases;
        $pointer->release = null;
        release($originalArray);
    };
    $arrayFacade->release = $arrayCallback;
    $schema = DuckDB\ArrowSchema::importFromC(address($schemaFacade));
    $batch = DuckDB\ArrowChunk::importFromC($schema, address($arrayFacade));
    check(FFI::isNull($schemaFacade->release) && FFI::isNull($arrayFacade->release), 'Native facade import must move ownership');
    $owners = [$originalSchema, $originalArray, $metadata, $schemaFacade, $arrayFacade, $schemaCallback, $arrayCallback];
    return [$schema, $batch, $owners];
}

function geometryMetadata(DuckDB\ArrowSchema $schema): array
{
    $field = $schema->toArray()['children'][0];
    $pairs = array_column($field['metadata'], 'value', 'key');
    check(($pairs['ARROW:extension:name'] ?? null) === 'geoarrow.wkb', 'GeoArrow extension name changed');
    return json_decode($pairs['ARROW:extension:metadata'] ?? '', true, 512, JSON_THROW_ON_ERROR);
}

$conn = (new DuckDB\Database())->connect();
$expected = $conn->query("SELECT 'POINT (1 2)'::GEOMETRY AS point")->fetchAll();
foreach ([['CUSTOM:123', 'authority_code'], ['999999', 'srid']] as [$crs, $kind]) {
    $schemaReleases = 0;
    $arrayReleases = 0;
    [$schema, $batch, $owners] = externalGeometry($conn, json_encode(['crs' => $crs, 'crs_type' => $kind], JSON_THROW_ON_ERROR), $schemaReleases, $arrayReleases);
    $native = $conn->dataChunkFromArrow($batch);
    check($batch->isConsumed(), 'Successful geometry import must consume its array');
    check($native->toRows() === $expected, 'Foreign CRS import changed WKB values');
    $metadata = geometryMetadata($native->arrowSchema($conn));
    check(($metadata['crs'] ?? null) === $crs && ($metadata['crs_type'] ?? null) === $kind, 'Native re-export erased an unknown CRS definition');
    $again = $native->toArrow($conn);
    check(geometryMetadata($again->schema())['crs'] === $crs, 'Array re-export erased unknown CRS metadata');
    $roundtrip = $conn->dataChunkFromArrow($again);
    check($roundtrip->toRows() === $expected, 'Foreign CRS roundtrip changed WKB values');
    check(geometryMetadata($roundtrip->arrowSchema($conn))['crs'] === $crs, 'Repeated import erased unknown CRS metadata');
    check($schemaReleases === 0 && $arrayReleases === 0, 'Native chunks must retain original producer ownership');
    unset($roundtrip, $again, $native, $batch, $schema);
    gc_collect_cycles();
    check($schemaReleases === 1 && $arrayReleases === 1, 'Foreign schema and array must release once');
    check(FFI::isNull($owners[0]->release) && FFI::isNull($owners[1]->release), 'Original native allocations were not released');
    unset($owners);
}
echo "noncatalog authority codes and SRIDs survive native conversion and repeated re-export\n";

$conn->query('CREATE TABLE markers(id INTEGER)');
foreach (['{', '[]', '{"crs":true}', '{"crs":{"unrelated":1}}'] as $json) {
    $schemaReleases = 0;
    $arrayReleases = 0;
    [$schema, $batch, $owners] = externalGeometry($conn, $json, $schemaReleases, $arrayReleases);
    $conn->beginTransaction();
    $conn->query('INSERT INTO markers VALUES (1)');
    try {
        $conn->dataChunkFromArrow($batch);
        throw new RuntimeException('Invalid GeoArrow metadata was accepted');
    } catch (DuckDB\Exception $error) {
        check(str_contains($error->getMessage(), 'GeoArrow') || str_contains($error->getMessage(), 'CRS'), 'Invalid metadata exception must identify its source');
    }
    check(!$batch->isConsumed(), 'Schema conversion errors must retain the source array');
    check($schemaReleases === 0 && $arrayReleases === 0, 'Schema conversion errors must not release available inputs');
    check($conn->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 1], 'Invalid metadata aborted the caller transaction');
    $conn->rollback();
    check($conn->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 0], 'Invalid metadata committed caller changes');
    // Exception traces retain the batch argument when argument capture is enabled.
    unset($error, $batch, $schema);
    gc_collect_cycles();
    check($schemaReleases === 1 && $arrayReleases === 1, 'Rejected schema and array must release once');
    unset($owners);
}
echo "malformed metadata rejects without consumption, duplicate releases or caller transaction loss\n";
?>
--EXPECT--
noncatalog authority codes and SRIDs survive native conversion and repeated re-export
malformed metadata rejects without consumption, duplicate releases or caller transaction loss
