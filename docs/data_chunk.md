# Data Chunks

PHP counterpart of the C API's data-chunk interface (`duckdb_data_chunk`,
`duckdb_fetch_chunk()`, `duckdb_create_data_chunk()`, …).

## Data chunks are internal to the driver

A data chunk is DuckDB's unit of data flow: a batch of up to **2048 rows**
(`duckdb_vector_size()`) in columnar layout. The C API exposes chunks so that
extensions can produce/consume data in engine-native batches.

In PHP there is no reason to handle columnar batches directly, so chunks are
**not exposed**. The driver uses them in exactly one place you should know
about:

## Streaming results

`Connection::queryStreaming()` / `Statement::executeStreaming()` iterate chunk
by chunk under the hood. Only one chunk is held in memory at a time, which is
what gives streaming results their constant-memory guarantee:

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
|---|---|
| Result fits comfortably in memory | `query()` + `fetchAll()` |
| Large/unbounded result, processed row-wise | `queryStreaming()` |
| Large result needed as one array anyway | `query()`, but mind the memory limit |

Caveats that follow from the chunk-based implementation:

- `Result::rowCount()` on a streaming result only counts materialized rows —
  it is not meaningful. Count with SQL (`SELECT count(*)`) if you need it.
- Starting a new execution on the same connection invalidates an in-flight
  streaming result (the next fetch throws). See [query.md](query.md).

## Not exposed

`duckdb_create_data_chunk()`, `duckdb_data_chunk_get_vector()`,
`duckdb_data_chunk_set_size()`, … are tools for writing C table/aggregation
functions, which this driver does not support from PHP — see
[table_functions.md](table_functions.md) and [vector.md](vector.md).
