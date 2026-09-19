# Overview

`duckdb` is a PHP extension (written in C++ against the Zend API) that embeds
[DuckDB](https://duckdb.org) — an in-process analytical database — directly into
PHP. There is no server, no socket and no daemon: the database engine runs
inside the PHP process, linked through the stable DuckDB C API (`libduckdb`).

- **Package**: [`martinjuul/duckdb`](https://packagist.org/packages/martinjuul/duckdb) (Composer metadata), PECL name `duckdb`
- **Requirements**: PHP 8.2+, `libduckdb` v1.1+ (v1.5.x recommended)
- **License**: MIT

## Installation

### PECL

```bash
pecl install duckdb
# then enable it
echo 'extension=duckdb.so' > "$(php -i | grep '^Scan this dir' | cut -d'>' -f2 | xargs)/duckdb.ini"
```

### From source

```bash
git clone https://github.com/martin-juul/php-duckdb.git
cd php-duckdb
phpize
./configure --with-duckdb=/path/to/duckdb   # dir containing include/duckdb.h and lib/libduckdb
make -j$(nproc)
sudo make install
```

### Docker

Pre-built images with the extension compiled in are published for PHP 8.2–8.5
on `linux/amd64` and `linux/arm64`:

```bash
docker pull ghcr.io/martin-juul/php-duckdb:8.4-cli
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

The extension pins the *stable* DuckDB C API. Two entry points it uses
(`duckdb_row_count`, `duckdb_value_varchar`) are deprecated upstream but have
no non-deprecated replacement; they remain functional and are isolated to
single, clearly commented call sites.
