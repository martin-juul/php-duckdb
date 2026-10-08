# php-duckdb Documentation

PHP API documentation for the `duckdb` extension (`martinjuul/duckdb`), a native
PHP driver for [DuckDB](https://duckdb.org) built on the stable DuckDB C API.

The page structure mirrors the
[DuckDB C API documentation](https://duckdb.org/docs/current/clients/c/overview),
so each page explains how to use `<C API thing>` from PHP. It also identifies
capabilities that are deliberately _not_ exposed and explains why.

## Contents

| DuckDB C API page                                                                | PHP client page                              |
| -------------------------------------------------------------------------------- | -------------------------------------------- |
| [Overview](https://duckdb.org/docs/current/clients/c/overview)                   | [overview.md](overview.md)                   |
| [Full API Reference](https://duckdb.org/docs/current/clients/c/api)              | [api.md](api.md)                             |
| [Connect](https://duckdb.org/docs/current/clients/c/connect)                     | [connect.md](connect.md)                     |
| [Configuration](https://duckdb.org/docs/current/clients/c/config)                | [config.md](config.md)                       |
| [Query](https://duckdb.org/docs/current/clients/c/query)                         | [query.md](query.md)                         |
| [Prepared Statements](https://duckdb.org/docs/current/clients/c/prepared)        | [prepared.md](prepared.md)                   |
| [Appender](https://duckdb.org/docs/current/clients/c/appender)                   | [appender.md](appender.md)                   |
| [Types](https://duckdb.org/docs/current/clients/c/types)                         | [types.md](types.md)                         |
| [Value](https://duckdb.org/docs/current/clients/c/value)                         | [value.md](value.md)                         |
| [Data Chunk](https://duckdb.org/docs/current/clients/c/data_chunk)               | [data_chunk.md](data_chunk.md)               |
| [Arrow conversion](https://duckdb.org/docs/current/clients/c/api)                | [arrow.md](arrow.md)                         |
| [Vector](https://duckdb.org/docs/current/clients/c/vector)                       | [vector.md](vector.md)                       |
| [Selection vectors](https://duckdb.org/docs/current/clients/c/vector)            | [selection.md](selection.md)                 |
| [Table Functions](https://duckdb.org/docs/current/clients/c/table_functions)     | [table_functions.md](table_functions.md)     |
| [COPY functions](https://duckdb.org/docs/current/clients/c/api)                  | [copy.md](copy.md)                           |
| [Replacement Scans](https://duckdb.org/docs/current/clients/c/replacement_scans) | [replacement_scans.md](replacement_scans.md) |

## PHP-specific topics

These pages cover driver-specific idioms with no corresponding C API page:

- [PHP application developer guide](php-developer-guide.md)
- [Runnable examples and public API map](../examples/README.md)
- [Error handling & the exception hierarchy](errors.md)
- [Asynchronous queries, fibers & event-loop integration](async.md)
- [FrankenPHP (classic & worker mode)](frankenphp.md)
- [PHP, DuckDB, and platform compatibility](compatibility.md)
- [Upgrade, rollback, and future migration policy](migrations.md)
- [Roadmap and Doctrine driver compatibility](roadmap.md)
- [Typed input coverage matrix](typed-coverage.md)

## Documentation checks

Run `python3 .agents/skills/markdown-lint/scripts/lint.py` from the repository
root. The [Markdown lint skill](../.agents/skills/markdown-lint/SKILL.md)
documents the pinned tool, configuration and checks for newly created files.
