# Table Functions

The C API registers table functions through
`duckdb_create_table_function()`, `duckdb_register_table_function()`, and
bind/init/function callbacks. PHP can inspect table-function catalog metadata
and call existing functions through SQL.

## Inspecting catalog metadata

`Connection::getTableFunctions(?string $name = null): array` returns one row
per table-function overload visible through this connection's
`duckdb_functions()` catalog. Omit the name to list all overloads, or pass an
exact, case-insensitive name. An unknown name returns an empty list.
A name containing a NUL byte throws `\ValueError`.
This API is under development and is not included in extension 1.4.1.

```php
foreach ($conn->getTableFunctions('range') as $function) {
    printf("%s.%s.%s(%s)\n", $function['catalog'], $function['schema'],
        $function['name'], implode(', ', $function['parameterTypes']));
}
```

Each row contains these keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `catalog` | `string` | Database/catalog name |
| `schema` | `string` | Schema name |
| `name` | `string` | Function name |
| `description` | `?string` | Catalog description, when supplied |
| `parameters` | `list<string>` | Catalog parameter names |
| `parameterTypes` | `list<string>` | Parallel SQL type names |
| `varargs` | `?string` | Variadic SQL type, or `null` |

Rows are sorted by catalog, schema, name and parameter types. Overloads remain
separate rows. Scalar functions, aggregates and macros are excluded. The
returned arrays are snapshots and remain usable after closing the connection.
The parallel lists flatten positional inputs followed by named inputs; order
among named inputs is determined by DuckDB's catalog. Discovery executes a
query and invalidates an active streaming result on the same connection.

The metadata describes declared inputs. Result columns can depend on arguments:
prepare an actual query and inspect `Statement::columnCount()`, `columnName()`
and `columnType()` to obtain its bound output schema without executing it.

```php
$statement = $conn->prepare('SELECT * FROM range(10)');
for ($column = 0; $column < $statement->columnCount(); $column++) {
    printf("%s: %s\n", $statement->columnName($column),
        $statement->columnType($column));
}
```

Catalog discovery uses SQL and does not expose native
`duckdb_table_function_bind_get_result_column_*` handles. Those C APIs inspect
columns inside a table-function bind callback, including target columns for
`COPY ... FROM`; PHP table-function and COPY FROM callbacks are not exposed.

## Registering PHP functions is not supported

DuckDB executes table functions through C callbacks that produce data chunks.
The driver does not connect PHP callables to these callbacks or the parallel
execution engine, so **you cannot register table functions from PHP**. The
same restriction applies to the C API's aggregate-function and cast
registration interfaces.

Scalar functions can be implemented in PHP with bind, worker-init and batch
execution callbacks; see [scalar functions](scalar_functions.md). These APIs
are under development and are not included in extension 1.4.1.

COPY functions are also supported: a PHP class can implement a `COPY ... TO`
output format. DuckDB hands each result batch to the request thread, where the
PHP handler writes it. See [COPY TO formats](copy.md).

## But: use the entire built-in table-function library via SQL

SQL queries can call every table function shipped with DuckDB or loaded by
an extension. These functions cover most custom data source needs:

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
| --- | --- |
| Query a PHP data structure | Insert it (Appender for bulk), or `json_encode()` it and read it with `read_json_auto` via a temp file |
| Reusable transformation over a source | `CREATE VIEW v AS SELECT … FROM read_parquet(…)`, then query `v` |
| Parameterized "function-like" query | A prepared statement, or a SQL macro: `CREATE MACRO f(x) AS …` |
| External database as a source | The `postgres`/`mysql`/`sqlite` DuckDB extensions via SQL |

## See also

- [replacement_scans.md](replacement_scans.md) — the other C-level extension
  point (also not exposed), with PHP-level alternatives.
