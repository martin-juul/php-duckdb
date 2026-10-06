# Data Chunks

The driver uses the C API's data-chunk interface internally (`duckdb_data_chunk`,
`duckdb_fetch_chunk()`, `duckdb_create_data_chunk()`, …).

## Columnar batches

A data chunk is DuckDB's unit of data flow: a batch in columnar layout.
Ordinary query batches have up to **2048 rows** (`duckdb_vector_size()`);
external Arrow batches can be larger. The C API exposes chunks so that
extensions can produce/consume data in engine-native batches.

Owning `DuckDB\DataChunk` objects come from `Connection::dataChunkFromArrow()`
or `DataChunk::fromVectors()`. They decode rows repeatedly, export Arrow
batches and append without being consumed. These APIs are under development
in this checkout and are not in the released 1.3.1 archive. See
[Arrow conversion](arrow.md) and [vectors](vector.md) for the APIs and
ownership contracts.

## Streaming results

`Connection::queryStreaming()` / `Statement::executeStreaming()` fetch one
chunk at a time. Keeping only that chunk in memory gives streaming results
their constant-memory guarantee:

```php
$result = $conn->queryStreaming('SELECT * FROM events');   // 500M rows? fine.
while ($row = $result->fetchRow()) {
    process($row);
}
```

Compare with the buffered path:

```php
$rows = $conn->query('SELECT * FROM events')->fetchAll();  // whole result set in memory
```

Practical guidance:

| Situation | Use |
| --- | --- |
| Result fits comfortably in memory | `query()` + `fetchAll()` |
| Large/unbounded result, processed row-wise | `queryStreaming()` |
| Columnar batch interchange or copying to an appender | `queryStreaming()` + `fetchArrowChunk()`; see [Arrow conversion](arrow.md) |
| Large result needed as one array anyway | `query()`, but mind the memory limit |

Caveats that follow from the chunk-based implementation:

- `Result::rowCount()` on a streaming result only counts materialized rows —
  it is not meaningful. Count with SQL (`SELECT count(*)`) if you need it.
- Starting a new execution on the same connection invalidates an in-flight
  streaming result (the next fetch throws). See [query.md](query.md).

## Building chunks from vectors

`DataChunk::fromVectors()` copies the first rows of named
[`DuckDB\Vector`](vector.md) columns into a new chunk, using
`duckdb_create_data_chunk()` and `duckdb_data_chunk_set_size()`. A chunk holds
at most `DuckDB\vectorSize()` rows. `DataChunk::vector()` copies one column
back out into a new vector. Chunks never share mutable storage with vectors:
appending or exporting a chunk is unaffected by later vector writes.

```php
$ids = $conn->createVector('INTEGER', 2);
$ids->setValues($conn, [1, 2]);
$chunk = DuckDB\DataChunk::fromVectors(['id' => $ids], 2);
$appender->appendChunk($chunk);
```

`DataChunk::select()` copies the rows a [selection vector](selection.md) names
into a new chunk of at most `DuckDB\vectorSize()` rows, for example to drop
rejected rows before appending or exporting a batch. It uses
`duckdb_vector_copy_sel()` for each column, and the new chunk holds its own
copy of the data.

```php
$valid = $chunk->select([0, 2]);
$appender->appendChunk($valid);
```

A chunk's own vectors are not exposed for in-place mutation. These chunks are
also not C table or aggregate function callbacks, which this driver does not
support from PHP; see [table_functions.md](table_functions.md).
