# Appender

An appender loads rows without executing a separate INSERT for each row. It
wraps the C Appender API (`duckdb_appender_create_ext()`, `duckdb_append_*()`,
`duckdb_appender_flush()` …).

## Basic usage

```php
$conn->query('CREATE TABLE events (id BIGINT, at TIMESTAMP, payload VARCHAR)');

$appender = $conn->appender('events');   // optional: ->appender('events', 'schema', 'catalog')

foreach ($events as [$id, $at, $payload]) {
    $appender->appendRow([$id, $at, $payload]);
}

$appender->close();   // flush + finish (also happens automatically on destruction)
```

`appender()` throws `CatalogException` when the table does not exist.

## Piecemeal rows

To build a row column by column, begin the row, append its values, then end it:

```php
$appender->beginRow();
$appender->append(42);
$appender->append(new DateTimeImmutable('now'));
$appender->appendDefault();   // use the column's DEFAULT
$appender->endRow();
```

Calling `beginRow()` while a row is open, or `endRow()` with no open row, throws
`\Error`. These calls violate the row lifecycle and are programming errors,
not database errors.

## Type mapping

The appender uses the same PHP→DuckDB value mapping as
[`Statement::bindValue()`](prepared.md#binding-types). It accepts `null`,
`bool`, `int`, `float`, `string`, `Interval` and `DateTimeInterface`; lists
become LIST values and associative arrays become STRUCT values.
[Native typed classes](value.md), such as `DuckDB\Decimal` and
`DuckDB\ListValue`, supply explicit SQL types. Typed conversion uses the same
connection and can invalidate an open stream. A conversion helper failure
before submission leaves the appender usable.

## Flushing and failure semantics

- Values are buffered and flushed to storage in batches. `flush()` forces a
  flush. `close()` permanently closes the appender (idempotent), flushing
  pending rows or discarding them if the appender has failed. Destruction
  performs a best-effort close with the same failure behavior.
- `appendRow()` converts **all** values before starting the row, so a PHP-side
  conversion failure (e.g. `\ValueError` for a bad value) leaves the appender
  usable.
- A native submission or flush failure throws an exception and marks the
  appender as failed. Further appends and flushes are rejected until `clear()`
  discards its buffered rows and any partial row. A helper conversion failure
  before submission leaves the appender usable without clearing it.
- `clear()` does not undo rows already flushed to the table. Use a transaction
  when recovery must roll back the entire batch. No rows are replayed
  automatically. `close()` on a failed appender discards pending data; a closed
  appender cannot be cleared or reopened.

```php
try {
    $appender->appendRow($row);
    $appender->flush();
} catch (DuckDB\Exception $e) {
    $appender->clear(); // explicitly discard all pending rows before reuse
    throw $e;
}
```

## Performance notes

- The appender avoids issuing a separate `INSERT` for each row. Benchmark it
  against prepared statements with your data. For the largest loads, consider
  `COPY ... FROM 'file.csv'` or
  `read_csv()` / `read_parquet()` via [`query()`](query.md). These bypass
  row-by-row processing entirely.
- Wrap huge appends in a transaction (`$conn->beginTransaction()` …
  `$conn->commit()`) to make them atomic.

## Not exposed

These C appender functions have no PHP wrapper:

| C API | PHP interface status |
| ------------------------------------------------------------------ | ---------------------------------------------------------------- |
| `duckdb_appender_column_count()` / `duckdb_appender_column_type()` | Column metadata is available via `DESCRIBE` / `query()` |
| `duckdb_appender_add_column()` / `duckdb_appender_clear_columns()` | Column subsetting for `append_default_to_chunk` workflows |
| `duckdb_append_default_to_chunk()` / `duckdb_append_data_chunk()` | Data chunks are not exposed (see [data_chunk.md](data_chunk.md)) |
| `duckdb_appender_create_query()` | Appending through a custom query requires an additional PHP interface |
