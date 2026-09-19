# Replacement Scans

PHP counterpart of the C API's replacement-scan interface
(`duckdb_add_replacement_scan()`).

## What replacement scans are

A replacement scan is a C callback that fires when DuckDB cannot resolve a
table reference, letting an application substitute its own data source — e.g.
making `SELECT * FROM 'anything'` mean "call my code". This is how some
integrations make arbitrary names queryable.

## Not exposed in PHP

Replacement scans require a C callback inside the planner's resolution path,
which this driver does not wire to PHP callables. There is no PHP API for
them.

## PHP-level alternatives

Everything a replacement scan is typically used for has a more explicit
equivalent here:

| Replacement-scan trick | PHP/SQL equivalent |
|---|---|
| `FROM 'file.xyz'` resolving to a custom reader | Call the right table function yourself: `read_csv()`, `read_parquet()`, `read_json()` — or wrap the choice in a small PHP helper that builds the SQL |
| Virtual tables computed on demand | `CREATE VIEW v AS …` (or `CREATE TEMP VIEW`), then query `v` |
| Named shortcuts for complex sources | SQL macros: `CREATE MACRO sales() AS TABLE SELECT * FROM read_parquet('s3://…')`, then `FROM sales()` |
| Fetching from another database by name | The `postgres` / `mysql` / `sqlite` extensions: `ATTACH` once, then reference real names |

Example — a tiny "scan resolver" in PHP:

```php
function resolve_source(string $name): string
{
    return match (true) {
        str_ends_with($name, '.csv')     => "read_csv('" . addslashes($name) . "')",
        str_ends_with($name, '.parquet') => "read_parquet('" . addslashes($name) . "')",
        default                          => '"' . addslashes($name) . '"',
    };
}

$rows = $conn->query('SELECT * FROM ' . resolve_source('events.parquet'))->fetchAll();
```

Because you assemble the SQL, the mapping is explicit, testable, and visible
to static analysis — which a global C callback would not be.
