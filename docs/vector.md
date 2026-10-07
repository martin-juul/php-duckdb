# Vectors

These APIs are under development in this checkout. They are not included in
the released 1.3.1 archive.

A vector is DuckDB's columnar storage for one column: a typed data buffer, a
validity mask for NULLs and, for nested types, child vectors. `DuckDB\Vector`
is an owned, fixed-capacity vector that PHP can create, fill, read and turn
into a [data chunk](data_chunk.md). Query results still decode to plain PHP
arrays; vectors are an explicit columnar building block.

## Create and fill

```php
$conn = (new DuckDB\Database())->connect();

$ids = $conn->createVector('BIGINT', 3);
$tags = $conn->createVector('VARCHAR[]', 3);
$ids->setValues($conn, [1, 2, 3]);
$tags->setValues($conn, [['new'], [], null]);

$ids->toArray();   // [1, 2, 3]
$tags->get(2);     // null
```

`Connection::createVector(string|Value $type, ?int $capacity = null)` accepts
the same type specifications as typed value classes: a SQL declaration, a
scalar value class name such as `DuckDB\Integer::class`, or a `DuckDB\Value`
whose declared type is used. The connection resolves catalog types such as
`CREATE TYPE` enums. The capacity defaults to `DuckDB\vectorSize()`, which is
2048 for the pinned engine, and may be 0 to 4294967295 rows.

Every row of a new vector is NULL, including nested children. Rows are
addressed from 0 to `capacity() - 1`; indices outside that range throw
`ValueError`.

## Writing values

| Method | Description |
| --- | --- |
| `set(Connection $connection, int $index, mixed $value): void` | Write one value |
| `setValues(Connection $connection, array $values, int $offset = 0): void` | Write a list of values to consecutive rows |
| `setNull(int $index): void` | Mark one row NULL |
| `copyFrom(Vector $source, int $sourceOffset = 0, ?int $count = null, int $targetOffset = 0): void` | Copy rows from a vector of the same type |
| `copySelected(Vector $source, SelectionVector\|array $selection, int $targetOffset = 0): void` | Gather the rows a [selection](selection.md) names from a vector of the same type |

Writes convert input exactly as typed binding of
`new DuckDB\Value($vector->type(), $value)` would: the same PHP shapes for
LIST, STRUCT, MAP and UNION, the same SQL casts and the same exceptions. A
string written to an `INTEGER` vector is cast; an out-of-range integer throws
`ConversionException`; JSON input is validated. See
[typed values](value.md) for the accepted input shapes.

Plain PHP booleans, integers, floats and strings that need no conversion are
written directly. Other input runs one conversion statement per batch on the
supplied connection. Like typed binding, that execution invalidates a
streaming result open on the same connection; use a separate connection for
vector writes while streaming. Any connection can convert input for a vector:
its type is fixed when the vector is created.

`setValues()` requires a list. It validates and converts every value before
changing a row, so a rejected value leaves the vector unchanged. `set()` and
`setValues()` overwrite rows in place. A replaced string or list keeps its
previous storage until the vector is freed; build a new vector for workloads
that rewrite large variable-size values many times.

`copyFrom()` copies `$count` rows (by default, through the end of the source)
from `$sourceOffset` into this vector at `$targetOffset`. The source must have
the same type, including DECIMAL precision, ENUM labels and nested field
names. Copying a vector into itself is supported, including overlapping
ranges.

## Reading values

| Method | Description |
| --- | --- |
| `type(): string` | Rendered type, as `DataChunk::columns()` reports it |
| `capacity(): int` | Number of rows |
| `get(int $index): mixed` | Decode one row |
| `isNull(int $index): bool` | Whether one row is NULL |
| `toArray(int $offset = 0, ?int $length = null): array` | Decode a range of rows; by default, through the capacity |
| `select(SelectionVector\|array $selection): Vector` | Copy the rows a [selection](selection.md) names into a new vector |

Values decode with the normal [PHP type mappings](types.md). `type()` renders
like result metadata: JSON reports `VARCHAR`, and GEOMETRY omits its CRS even
though the vector retains it. Export a chunk's Arrow schema to inspect the
CRS.

## Data chunks

```php
$chunk = DuckDB\DataChunk::fromVectors(['id' => $ids, 'tags' => $tags], 3);

$writer = $db->connect();
$appender = $writer->appender('items');
$appender->appendChunk($chunk);
$appender->close();

$column = $chunk->vector(1);   // an owned copy of the tags column
```

`DataChunk::fromVectors(array $vectors, int $rowCount)` copies the first
`$rowCount` rows of each vector into a new chunk. Keys are column names and
must be strings. Each vector needs a capacity of at least `$rowCount`, and a
chunk holds at most `DuckDB\vectorSize()` rows. To split a larger vector,
copy each range into a smaller vector with `copyFrom()`.

Chunks own a copy of their data: writing to a vector afterwards does not
change a chunk built from it. `DataChunk::vector(int $index)` likewise
returns a new vector whose capacity is the chunk's row count. It copies any
native layout, including dictionary-encoded columns imported from Arrow, into
a flat vector.

A vector-built chunk supports every `DataChunk` operation:
`Appender::appendChunk()`, `toRows()`, and Arrow export with `arrowSchema()`
and `toArrow()`. See [Arrow conversion](arrow.md).

## Ownership and lifetime

A vector owns its native memory. It remains usable after the connection and
database that created it are closed or freed. Chunks built from vectors and
vectors copied from chunks are independent of their sources. Vector memory is
allocated by DuckDB and does not count toward PHP's `memory_limit`.

Vectors are final, cannot be cloned or serialized, and have a private
constructor. A `DuckDB\Vector` must not be shared between threads.

## C API correspondence

| C API | PHP |
| --- | --- |
| `duckdb_create_vector()`, `duckdb_destroy_vector()` | `Connection::createVector()`; freed with the object |
| `duckdb_vector_get_column_type()` | `type()` |
| `duckdb_vector_get_data()`, `duckdb_vector_assign_string_element_len()` | `set()`, `setValues()` |
| `duckdb_vector_get_validity()`, `duckdb_validity_set_row_invalid()` | `isNull()`, `setNull()` |
| `duckdb_vector_reference_value()` | Used internally by writes |
| `duckdb_vector_copy_sel()` | `copyFrom()`, chunk copies and [selections](selection.md) |
| `duckdb_create_data_chunk()`, `duckdb_data_chunk_get_vector()` | `DataChunk::fromVectors()`, `DataChunk::vector()` |
| `duckdb_vector_size()` | `DuckDB\vectorSize()` |

PHP has no raw buffer or validity-mask access. Vectors stay flat: there is no
public `duckdb_vector_reference_vector()`, constant-vector or slicing API.
[Selection vectors](selection.md) pick, reorder and repeat rows by copying
them into new flat vectors and chunks. Vectors are not a user-defined
function interface; see [table functions](table_functions.md).

See the runnable [vector example](../examples/vectors.php).
