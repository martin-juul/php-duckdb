# php-duckdb Documentation

PHP API documentation for the `duckdb` extension (`martinjuul/duckdb`), a native
PHP driver for [DuckDB](https://duckdb.org) built on the stable DuckDB C API.

The page structure mirrors the
[DuckDB C API documentation](https://duckdb.org/docs/current/clients/c/overview),
so every page answers the question "how do I do `<C API thing>` from PHP?" —
including the things that are deliberately *not* exposed and why.

## Contents

| DuckDB C API page | PHP client page |
|---|---|
| [Overview](https://duckdb.org/docs/current/clients/c/overview) | [overview.md](overview.md) |
| [Full API Reference](https://duckdb.org/docs/current/clients/c/api) | [api.md](api.md) |
| [Connect](https://duckdb.org/docs/current/clients/c/connect) | [connect.md](connect.md) |
| [Configuration](https://duckdb.org/docs/current/clients/c/config) | [config.md](config.md) |
| [Query](https://duckdb.org/docs/current/clients/c/query) | [query.md](query.md) |
| [Prepared Statements](https://duckdb.org/docs/current/clients/c/prepared) | [prepared.md](prepared.md) |
| [Appender](https://duckdb.org/docs/current/clients/c/appender) | [appender.md](appender.md) |
| [Types](https://duckdb.org/docs/current/clients/c/types) | [types.md](types.md) |
| [Value](https://duckdb.org/docs/current/clients/c/value) | [value.md](value.md) |
| [Data Chunk](https://duckdb.org/docs/current/clients/c/data_chunk) | [data_chunk.md](data_chunk.md) |
| [Vector](https://duckdb.org/docs/current/clients/c/vector) | [vector.md](vector.md) |
| [Table Functions](https://duckdb.org/docs/current/clients/c/table_functions) | [table_functions.md](table_functions.md) |
| [Replacement Scans](https://duckdb.org/docs/current/clients/c/replacement_scans) | [replacement_scans.md](replacement_scans.md) |

## PHP-specific topics

These have no C API counterpart page because they are idioms of this driver:

- [Error handling & the exception hierarchy](errors.md)
- [Asynchronous queries, fibers & event-loop integration](async.md)
