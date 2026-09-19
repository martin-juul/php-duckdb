# Table Functions

PHP counterpart of the C API's table-function registration interface
(`duckdb_create_table_function()`, `duckdb_register_table_function()`,
bind/init/function callbacks, …).

## Registering PHP functions is not supported

A DuckDB table function is driven by C callbacks that produce data chunks.
Wiring PHP callables into that hot path (and into the parallel execution
engine) is not part of this driver, so **you cannot register table functions
from PHP**. The same applies to the C API's scalar-function, aggregate-function
and cast registration interfaces.

## But: use the entire built-in table-function library via SQL

Everything DuckDB ships (or loads as an extension) is available through plain
queries — this covers most real-world "custom data source" needs:

```php
// Files
$conn->query("SELECT * FROM read_csv('data/*.csv', header => true)");
$conn->query("SELECT * FROM read_parquet('s3://bucket/events/*.parquet')");   // needs httpfs
$conn->query("SELECT * FROM read_json('logs.ndjson')");

// Generators
$conn->query('SELECT * FROM range(10)');
$conn->query("SELECT * FROM glob('*.csv')");

// Introspection
$conn->query('SELECT * FROM duckdb_tables()');
$conn->query('SELECT * FROM duckdb_settings()');
```

Load extra function families as DuckDB extensions:

```php
$conn->query("INSTALL httpfs; LOAD httpfs;");
$conn->query("INSTALL postgres; LOAD postgres;");   // query PostgreSQL directly
```

## Recipes for custom data

| Want | Do this |
|---|---|
| Query a PHP data structure | Insert it (Appender for bulk), or `json_encode()` it and read it with `read_json_auto` via a temp file |
| Reusable transformation over a source | `CREATE VIEW v AS SELECT … FROM read_parquet(…)`, then query `v` |
| Parameterized "function-like" query | A prepared statement, or a SQL macro: `CREATE MACRO f(x) AS …` |
| External database as a source | The `postgres`/`mysql`/`sqlite` DuckDB extensions via SQL |

## See also

- [replacement_scans.md](replacement_scans.md) — the other C-level extension
  point (also not exposed), with PHP-level alternatives.
