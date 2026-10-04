# Data Chunks

The driver uses the C API's data-chunk interface internally (`duckdb_data_chunk`,
`duckdb_fetch_chunk()`, `duckdb_create_data_chunk()`, …).

## Columnar batches

A data chunk is DuckDB's unit of data flow: a batch in columnar layout.
Ordinary query batches have up to **2048 rows** (`duckdb_vector_size()`);
external Arrow batches can be larger. The C API exposes chunks so that
extensions can produce/consume data in engine-native batches.

The current Arrow feature exposes owning `DuckDB\DataChunk` objects through
`Connection::dataChunkFromArrow()`. They decode rows repeatedly, export Arrow
batches and append without being consumed. These APIs are under development
in this checkout and are not in the released 1.3.1 archive. See
[Arrow conversion](arrow.md) for the API and ownership contract.

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

## Native vector access

PHP can obtain a native chunk by importing Arrow, but cannot construct or
mutate its vectors directly.

`duckdb_create_data_chunk()`, `duckdb_data_chunk_get_vector()`,
`duckdb_data_chunk_set_size()`, … are tools for writing C table/aggregation
functions, which this driver does not support from PHP — see
[table_functions.md](table_functions.md) and [vector.md](vector.md).
