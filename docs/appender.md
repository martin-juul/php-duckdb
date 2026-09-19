# Appender

PHP counterpart of the C Appender API (`duckdb_appender_create_ext()`,
`duckdb_append_*()`, `duckdb_appender_flush()` …) — the fastest way to load
bulk data row by row, bypassing the SQL layer.

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

When you build rows column by column:

```php
$appender->beginRow();
$appender->append(42);
$appender->append(new DateTimeImmutable('now'));
$appender->appendDefault();   // use the column's DEFAULT
$appender->endRow();
```

Calling `beginRow()` while a row is open (or `endRow()` with no open row)
throws `\Error` — that is a programming error, not a database error.

## Type mapping

Appender values follow exactly the same PHP→DuckDB mapping as
[`Statement::bindValue()`](prepared.md#binding-types): `null`, `bool`, `int`,
`float`, `string`, `Interval`, `DateTimeInterface`, lists → LIST, associative
arrays → STRUCT.

## Flushing and failure semantics

- Values are buffered and flushed to storage in batches. `flush()` forces a
  flush; `close()` flushes and invalidates the appender (idempotent).
  Destruction performs a best-effort close.
- `appendRow()` converts **all** values before starting the row, so a PHP-side
  conversion failure (e.g. `\ValueError` for a bad value) leaves the appender
  usable.
- A failure reported *by DuckDB* (constraint violation, type conversion in the
  engine) throws the typed exception **and invalidates the appender** —
  discard it and create a new one.

## Performance notes

- The appender is dramatically faster than per-row `INSERT` statements for
  large loads. For the largest loads, consider
  `COPY ... FROM 'file.csv'` or `read_csv()` / `read_parquet()` via
  [`query()`](query.md), which bypass row-by-row processing entirely.
- Wrap huge appends in a transaction
  (`$conn->beginTransaction()` … `$conn->commit()`) to make them atomic.

## Not exposed

These C appender functions have no PHP wrapper (all niche or internal):

| C API | Why it's not needed in PHP |
|---|---|
| `duckdb_appender_column_count()` / `duckdb_appender_column_type()` | Column metadata is available via `DESCRIBE` / `query()` |
| `duckdb_appender_add_column()` / `duckdb_appender_clear_columns()` | Column subsetting for `append_default_to_chunk` workflows |
| `duckdb_append_default_to_chunk()` / `duckdb_append_data_chunk()` | Data chunks are not exposed (see [data_chunk.md](data_chunk.md)) |
| `duckdb_appender_create_query()` | Diagnostics helper; errors surface as exceptions |
