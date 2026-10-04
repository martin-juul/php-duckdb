--TEST--
Arrow failure cleanup: consumed invalid arrays release PHP FFI producers without losing exceptions or transactions
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
$result = $conn->query("SELECT map([1::INTEGER, 2::INTEGER], ['a', 'b']) AS m");
$schemaStorage = $ffi->new('struct ArrowSchema');
$arrayStorage = $ffi->new('struct ArrowArray');
$result->arrowSchema()->exportToC(address($schemaStorage));
$result->fetchArrowChunk()->exportToC(address($arrayStorage));
check(FFI::string($schemaStorage->children[0]->format) === '+m', 'Expected Arrow map storage');
check(FFI::string($schemaStorage->children[0]->children[0]->children[0]->format) === 'i', 'Expected int32 map keys');
$keys = $ffi->cast('int *', $arrayStorage->children[0]->children[0]->children[0]->buffers[1]);
check($keys[0] === 1 && $keys[1] === 2, 'Expected two distinct source keys');
$keys[1] = $keys[0];

$schemaReleases = 0;
$arrayReleases = 0;
$autoloadCalls = 0;
spl_autoload_register(static function (string $class) use (&$autoloadCalls): void {
    if ($class === 'ArrowReleaseAutoloadProbe') {
        ++$autoloadCalls;
    }
});
$schemaFacade = $ffi->new('struct ArrowSchema');
FFI::memcpy(FFI::addr($schemaFacade), FFI::addr($schemaStorage), FFI::sizeof($schemaStorage));
$schemaCallback = static function ($pointer) use ($schemaStorage, &$schemaReleases): void {
    ++$schemaReleases;
    $pointer->release = null;
    release($schemaStorage);
};
$schemaFacade->release = $schemaCallback;
$arrayFacade = $ffi->new('struct ArrowArray');
FFI::memcpy(FFI::addr($arrayFacade), FFI::addr($arrayStorage), FFI::sizeof($arrayStorage));
$arrayCallback = static function ($pointer) use ($arrayStorage, &$arrayReleases): void {
    ++$arrayReleases;
    class_exists('ArrowReleaseAutoloadProbe');
    try {
        throw new RuntimeException('Caught callback exception');
    } catch (RuntimeException $nested) {
        // A handled callback error must not replace the pending import error.
    }
    gc_collect_cycles();
    $pointer->release = null;
    release($arrayStorage);
};
$arrayFacade->release = $arrayCallback;
$schema = DuckDB\ArrowSchema::importFromC(address($schemaFacade));
$batch = DuckDB\ArrowChunk::importFromC($schema, address($arrayFacade));
check(FFI::isNull($schemaFacade->release) && FFI::isNull($arrayFacade->release), 'Facade import must move ownership');
unset($result);

$conn->query('CREATE TABLE markers(id INTEGER)');
$conn->beginTransaction();
$conn->query('INSERT INTO markers VALUES (1)');
try {
    $conn->dataChunkFromArrow($batch);
    throw new RuntimeException('Duplicate map keys were accepted');
} catch (DuckDB\Exception $error) {
    check(str_contains($error->getMessage(), 'Arrow map contains duplicate key'), 'Conversion exception was lost during producer cleanup');
}
check($batch->isConsumed() && $batch->rowCount() === 1, 'Failed native conversion must consume its array');
check($arrayReleases === 1 && $schemaReleases === 0, 'Failed native conversion must release array while preserving available schema');
check($autoloadCalls === 1, 'Producer cleanup must permit nested autoload callbacks');
check(FFI::isNull($arrayStorage->release), 'Original producer array was not released');
check($batch->schema()->toArray()['children'][0]['format'] === '+m', 'Consumed batch schema remains readable');
check($conn->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 1], 'Recoverable Arrow error invalidated caller transaction');
$conn->rollback();
check($conn->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 0], 'Arrow error committed caller changes');
echo "failed native conversion preserves its exception while releasing PHP FFI producer arrays\n";

// Captured argument traces retain the consumed batch until the exception is dropped.
unset($error, $batch, $schema);
gc_collect_cycles();
check($arrayReleases === 1 && $schemaReleases === 1, 'Consumed array and retained schema must each release exactly once');
check(FFI::isNull($schemaStorage->release), 'Original producer schema was not released');
echo "consumed batches retain schemas and caller transactions without duplicate releases\n";
?>
--EXPECT--
failed native conversion preserves its exception while releasing PHP FFI producer arrays
consumed batches retain schemas and caller transactions without duplicate releases
