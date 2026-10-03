# Replacement Scans

The C API provides replacement scans through
`duckdb_add_replacement_scan()`. This page describes the mechanism and its
PHP-level alternatives.

## What replacement scans are

When DuckDB cannot resolve a table reference, a replacement scan invokes a
C callback that lets the application substitute its own data source. Some
integrations use this to make arbitrary names queryable: for example,
`SELECT * FROM 'anything'` can mean "call my code".

## Not exposed in PHP

Replacement scans require a C callback inside the planner's resolution path,
which this driver does not wire to PHP callables. There is no PHP API for
them.

## PHP-level alternatives

Typical uses of replacement scans have explicit PHP or SQL equivalents:

| Replacement-scan trick | PHP/SQL equivalent |
| --- | --- |
| `FROM 'file.xyz'` resolving to a custom reader | Call the right table function yourself: `read_csv()`, `read_parquet()`, `read_json()` — or wrap the choice in a small PHP helper that builds the SQL |
| Virtual tables computed on demand | `CREATE VIEW v AS …` (or `CREATE TEMP VIEW`), then query `v` |
| Named shortcuts for complex sources | SQL macros: `CREATE MACRO sales() AS TABLE SELECT * FROM read_parquet('s3://…')`, then `FROM sales()` |
| Fetching from another database by name | The `postgres` / `mysql` / `sqlite` extensions: `ATTACH` once, then reference real names |

This PHP helper returns a SQL source expression and its parameters. Filenames
are bound as values; table names are quoted as identifiers by doubling embedded
double quotes. The helper rejects NUL bytes:

```php
function resolve_source(string $name): array
{
    if (str_contains($name, "\0")) {
        throw new ValueError('Source names cannot contain NUL bytes');
    }

    return match (true) {
        str_ends_with($name, '.csv')     => ['read_csv(?)', [$name]],
        str_ends_with($name, '.parquet') => ['read_parquet(?)', [$name]],
        default => ['"' . str_replace('"', '""', $name) . '"', []],
    };
}

[$source, $parameters] = resolve_source('events.parquet');
$rows = $conn->execute('SELECT * FROM ' . $source, $parameters)->fetchAll();
```

The mapping is explicit because the PHP helper assembles the SQL. It can be
tested and inspected by static analysis, unlike a global C callback.
