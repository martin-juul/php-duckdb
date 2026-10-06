# Arrow conversion

These APIs are under development in this checkout. They are not included in
the released 1.3.1 archive.

The extension exposes Arrow schemas and record batches through the
[Arrow C Data Interface](https://arrow.apache.org/docs/format/CDataInterface.html).
This is an in-process interface using native structs, buffers and release
callbacks. It does not produce Arrow IPC files, IPC streams or Flight messages.
There is no public `ArrowArrayStream` wrapper.

`DuckDB\ArrowSchema`, `DuckDB\ArrowChunk` and `DuckDB\DataChunk` have private
constructors. Obtain them from a result, Arrow import or native conversion.
`DataChunk::fromVectors()` also builds native chunks from
[vectors](vector.md).
Schema inspection, batch fetching, conversion and appending work without PHP
FFI. FFI is useful when exchanging native addresses with another library.

## Fetch and copy batches

```php
$db = new DuckDB\Database();
$source = $db->connect();
$source->query('SET arrow_lossless_conversion = true');
$destination = $db->connect();
$destination->query('CREATE TABLE copied(id INTEGER, tags VARCHAR[])');
$appender = $destination->appender('copied');
$result = $source->queryStreaming("SELECT i::INTEGER AS id, ['arrow', NULL] AS tags FROM range(5) t(i)");

$description = $result->arrowSchema()->toArray();
while (($arrow = $result->fetchArrowChunk()) !== null) {
    $chunk = $destination->dataChunkFromArrow($arrow);
    $appender->appendChunk($chunk);
}

$appender->close();
```

`Result::arrowSchema()` inspects the result without consuming rows, including
an empty result. `fetchArrowChunk()` advances by one batch and returns `null`
at exhaustion. Both buffered and streaming results support these methods.
Buffered queries still materialize the full result; use streaming queries to
process large results batch by batch.

A partially read row batch cannot be fetched as Arrow. After `fetchRow()`,
`fetchColumn()` or iteration has read part of a batch, `fetchArrowChunk()`
throws without discarding its unread rows. Continue reading rows until the
batch boundary before switching. Row fetching after a complete Arrow batch
is allowed. Streaming results retain the [one-stream rule](query.md): starting
another execution on their connection invalidates them. Use a separate
connection for destination queries and appenders.

See the runnable [batch copy example](../examples/arrow.php).

## Schema inspection

`ArrowSchema::toArray()` returns a recursive description:

```php
[
    'name' => '',
    'format' => '+s',
    'flags' => 0,
    'metadata' => [],
    'children' => [
        [
            'name' => 'id',
            'format' => 'i',
            'flags' => 2,
            'metadata' => [],
            'children' => [],
            'dictionary' => null,
        ],
    ],
    'dictionary' => null,
]
```

This illustrates the shape; actual flags and formats depend on the schema and
connection settings. Metadata is a list of `['key' => string, 'value' => string]`
pairs, preserving order, duplicate keys and binary contents. `dictionary` is
another recursive schema or `null`. A record batch uses a struct root (`+s`),
whose children describe the columns.

## Native chunks and ownership

| Operation | Ownership |
| --- | --- |
| `ArrowSchema::importFromC(int $address)` | Moves the native schema; clears the source release callback |
| `ArrowSchema::exportToC(int $address)` | Copies the schema into an empty native destination; PHP schema remains reusable |
| `ArrowChunk::importFromC(ArrowSchema $schema, int $address)` | Moves the native array; retains its schema |
| `ArrowChunk::exportToC(int $address)` | Moves the array into an empty native destination; consumes the PHP chunk |
| `Connection::dataChunkFromArrow(ArrowChunk $chunk)` | Converts to a native `DataChunk` and consumes the Arrow chunk |
| `Appender::appendArrow(ArrowChunk $chunk)` | Converts and appends the batch, consuming it |
| `Appender::appendChunk(DataChunk $chunk)` | Appends the native chunk without consuming it |

`ArrowChunk::schema()` and `rowCount()` remain readable after consumption;
`isConsumed()` reports its state. A consumed chunk cannot be converted or
exported again. A returned batch and its schema can outlive their source
result and connection. `DataChunk` keeps any imported Arrow buffers alive for
its own lifetime and can also outlive the importing connection.

`DataChunk::rowCount()`, `columnCount()` and `columns()` expose its dimensions
and a list of `['name' => string, 'type' => string]` columns. `toRows()` decodes
all rows without consuming them, using the normal [PHP type mappings](types.md).
It accepts `FetchMode::Assoc` (default), `Num` or `Both` and can be called again.
`arrowSchema(Connection $connection)` and `toArrow(Connection $connection)`
export a schema or a new Arrow batch; neither consumes the native chunk.

### Type conversion settings

`Database` enables `arrow_lossless_conversion=true` by default. An explicit
configuration value is honored:

```php
$db = new DuckDB\Database(':memory:', ['arrow_lossless_conversion' => false]);
$conn = $db->connect();
$conn->query('SET arrow_lossless_conversion = true');
```

Result export uses the settings captured for its query; native chunk export uses
the connection supplied to `arrowSchema()` or `toArrow()`. Lossless conversion
uses Arrow extension metadata, which foreign consumers must understand.

When lossless conversion is disabled, Result and DataChunk export reject
representations that cannot preserve HUGEINT, UHUGEINT, BIT or TIMETZ,
including these types inside nested values. Export raises an exception asking
to enable `arrow_lossless_conversion` instead of silently changing their type
or corrupting values. UUID, JSON and ENUM can still use DuckDB's ordinary
Arrow representations when lossless conversion is disabled; native import may
normalize their types to strings or dictionary values. Inspect `columns()` and
the exported schema when preserving the exact type declaration matters.
VARIANT Arrow export remains unsupported by the pinned engine and throws.

The bundled SDK includes an
[Arrow engine patch](../packaging/duckdb/patches/README.md) that supplies implicit
transaction handling for C conversions and preserves GEOMETRY CRS metadata
on import. Conversion therefore does not require a caller-managed transaction
with this SDK. Existing explicit transactions continue to own the operation;
expected validation/conversion errors leave them usable. The patch retains a
declared CRS even when the coordinate catalog cannot resolve its identifier,
and rejects CRS descriptions it cannot represent.

External or distribution-provided DuckDB SDKs are used as supplied and do not
receive this patch when the PHP extension is compiled. An unpatched engine can
require an explicit transaction for Arrow export catalog lookups and can discard
a declared CRS when its coordinate catalog cannot resolve it. The binding checks
native CRS after import and throws when GeoArrow metadata declared a CRS but the
converted type has none, including nested, dictionary and run-end encoded
geometry. Known catalog-resolved CRS can still roundtrip on an older SDK. These
paths require validation against the actual linked engine. Raw `ArrowSchema`
import/export copies schema metadata independently of native DuckDB type
conversion.

`DataChunk::columns()` renders a type summary and can report plain `GEOMETRY`
even when the native type retains a CRS. Inspect the GeoArrow extension
metadata from `arrowSchema($connection)->toArray()` to check its CRS.

Conversion uses DuckDB's supported Arrow types and the supplied connection's
conversion settings. Result export uses the settings captured for its query.
This can affect timezones, string/list layouts and extension types. Importing
and exporting through a native chunk preserves supported values but need not
reproduce the original DuckDB type declaration, Arrow metadata or dictionary
encoding. Inspect `columns()` and the exported schema when these distinctions
matter. Unsupported Arrow conversions throw; support is determined by the
linked DuckDB engine. The implementation uses DuckDB's
[Arrow conversion C API](https://github.com/duckdb/duckdb/blob/v1.5.6/src/include/duckdb.h).

Batch appending requires a compatible destination table and no open piecemeal
row. A native submission or flush failure puts the appender in a failed state;
call `clear()` to discard buffered and partial rows before reuse, or `close()`
to discard and close it. Already-flushed rows remain subject to the surrounding
transaction. Arrow input can already be consumed when submission fails; check
`isConsumed()` before retrying. A native chunk remains reusable after an append
failure. See [appender recovery](appender.md).

## Native address exchange with FFI

Addresses must point to valid, aligned `ArrowSchema` or `ArrowArray` structs
with the C Data Interface ABI. Destinations must be zero-initialized or have
already been released. Invalid native addresses cannot be made safe by PHP
argument checks. Imported arrays require a matching struct-root schema. The
record-batch root must not contain null rows; null values in its columns are
supported. External batches can exceed the usual DuckDB vector size, but
native conversion limits row counts to `UINT32_MAX`. Sliced batches with a
nonzero root offset are normalized during native conversion.

This example exports a result into FFI-owned structs and moves them back into
PHP. It requires FFI to be installed and enabled:

```php
$ffi = FFI::cdef(<<<'C'
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
    return FFI::cast('uintptr_t', FFI::addr($value))->cdata;
}

$conn = (new DuckDB\Database())->connect();
$result = $conn->query('SELECT 42::INTEGER AS id');
$cSchema = $ffi->new('struct ArrowSchema');
$cArray = $ffi->new('struct ArrowArray');
$schema = $result->arrowSchema();
$schema->exportToC(address($cSchema));
$result->fetchArrowChunk()->exportToC(address($cArray));

$importedSchema = DuckDB\ArrowSchema::importFromC(address($cSchema));
$importedArray = DuckDB\ArrowChunk::importFromC($importedSchema, address($cArray));
$rows = $conn->dataChunkFromArrow($importedArray)->toRows();
// [['id' => 42]]; both source C structs now have null release callbacks.
```

If a native consumer does not move an exported struct, it must call that
struct's release callback once when finished. For example:

```php
if (!FFI::isNull($cArray->release)) {
    ($cArray->release)(FFI::addr($cArray));
}
```

Use the same pattern for an unconsumed exported schema. Arrange cleanup when
native processing throws, too. Never release a moved source a second time.

For external producers, the producer's callback owns release of its children,
dictionaries and buffers. When PHP FFI supplies callbacks or allocations,
keep their PHP owners alive until the imported Arrow/native chunk and all
native consumers have released them. Moving a struct does not keep arbitrary
PHP FFI allocations or closures alive. The extension manages the moved C Data
Interface object; the caller manages the producer's PHP resources.
