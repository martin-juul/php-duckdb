# Overview

`duckdb` is a PHP extension (written in C++ against the Zend API) that embeds
[DuckDB](https://duckdb.org) — an in-process analytical database — directly into
PHP. There is no server, no socket and no daemon: the database engine runs
inside the PHP process, linked through the stable DuckDB C API (`libduckdb`).

- **Package**: [`martinjuul/duckdb`](https://packagist.org/packages/martinjuul/duckdb) (PIE), extension name `duckdb`
- **Requirements**: PHP 8.2+; pinned builds target `libduckdb` v1.5.6 (see [compatibility](compatibility.md))
- **License**: MIT

## Installation

### PIE (Linux/macOS)

Install [PIE](https://php.github.io/pie/), a C++17 compiler, `make`,
`autoconf`, and the target PHP version's development tools (`phpize` and
`php-config`). Install `libduckdb` separately under a prefix containing
`include/duckdb.h` and `lib/libduckdb.so` (or `.dylib` on macOS).

```bash
pie install martinjuul/duckdb --with-duckdb=/opt/duckdb
```

On macOS, use `--with-duckdb=$(brew --prefix duckdb)`. PIE attempts to
enable the extension automatically; follow its instructions if enabling
fails. Windows PIE binaries are not provided yet.

### From source

```bash
git clone https://github.com/martin-juul/php-duckdb.git
cd php-duckdb
phpize
./configure --with-duckdb=/path/to/duckdb   # dir containing include/duckdb.h and lib/libduckdb
make -j$(nproc)
sudo make install
```

Enable the source-built extension by adding `extension=duckdb.so` to
your PHP configuration. On Windows, build using `config.w32` and the PHP SDK.

### Windows ZIP packages

The packaging workflow builds x64 ZIPs for PHP 8.2–8.5, in both TS and NTS
variants. Choose the archive matching your PHP minor version, thread-safety
mode, and compiler. Follow the [Windows installation guide](../packaging/windows/README.md)
for DLL placement, runtime prerequisites, and configuration. Availability is
determined by the assets on the selected release; older tags are not backfilled.

### Docker

Pre-built images with the extension compiled in are published for PHP 8.2–8.5
on `linux/amd64` and `linux/arm64`:

```bash
docker pull ghcr.io/martin-juul/php-duckdb:8.4
```

## Quickstart

```php
<?php
use DuckDB\Database;

$db   = new Database(':memory:');          // or '/path/to/file.duckdb'
$conn = $db->connect();

$conn->query('CREATE TABLE users (id INTEGER, name VARCHAR)');
$conn->execute('INSERT INTO users VALUES (?, ?)', [1, 'Alice']);

$result = $conn->query('SELECT * FROM users');
foreach ($result as $row) {
    echo $row['name'], PHP_EOL;            // Alice
}
```

See the [`examples/`](../examples) directory for runnable scripts covering sync,
prepared, async, transaction, appender and error-handling workflows.

## How the C API maps to PHP

The driver is a thin, object-oriented layer over the C API. If you know the C
API, the PHP surface will feel familiar:

| C API concept | PHP counterpart |
|---|---|
| `duckdb_database` + `duckdb_open_ext()` | `DuckDB\Database` (constructor) |
| `duckdb_connection` + `duckdb_connect()` | `DuckDB\Connection` via `Database::connect()` |
| `duckdb_query()` | `Connection::query()` / `Connection::execute()` |
| streaming chunks (`duckdb_fetch_chunk()`) | `Connection::queryStreaming()` (chunks decoded internally) |
| `duckdb_prepared_statement` | `DuckDB\Statement` via `Connection::prepare()` |
| `duckdb_bind_*()` | `Statement::bindValue()` / `Statement::bindBlob()` |
| `duckdb_pending_*()` | `DuckDB\PendingQuery` via `queryAsync()` / `queryPending()` / `executeAsync()` |
| `duckdb_appender` | `DuckDB\Appender` via `Connection::appender()` |
| `duckdb_result` | `DuckDB\Result` (and `DuckDB\ResultIterator`) |
| `duckdb_error_type` | `DuckDB\ErrorType` enum + typed exceptions |
| `duckdb_interval` | `DuckDB\Interval` |
| `duckdb_library_version()` | `DuckDB\version()` |
| `duckdb_interrupt()` | `Connection::interrupt()` |
| `duckdb_query_progress()` | `Connection::queryProgress()` |
| `duckdb_get_table_names()` | `Connection::getTableNames()` |

## Threading & safety model

- A `Database` can back **many connections**; each `Connection` serializes the
  statements issued on it. Use one connection per thread/fiber of work.
- Async queries run on background worker threads that never touch PHP state;
  completion is reported through a file descriptor / stream you can poll or
  suspend a fiber on. See [async.md](async.md).
- File databases take an **exclusive write lock** at open time. Only one
  process at a time can open a file for writing. See [connect.md](connect.md).

## Stability

The current pinned builds target DuckDB 1.5.6. The driver uses three C entry
points deprecated upstream: `duckdb_pending_prepared_streaming` for streaming
prepared execution, `duckdb_row_count` for materialized row counts, and
`duckdb_value_varchar` for fallback string rendering. These internal dependencies
do not deprecate the corresponding PHP methods. Replacements must preserve
existing behavior before they are adopted.

See [compatibility](compatibility.md) for configured platform coverage and the
1.5.6 API audit, and [migrations](migrations.md) for upgrade and rollback guidance.
