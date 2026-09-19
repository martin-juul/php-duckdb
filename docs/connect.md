# Connecting

PHP counterpart of the C API's `duckdb_open()` / `duckdb_connect()` /
`duckdb_disconnect()` / `duckdb_close()`.

## Opening a database

```php
use DuckDB\Database;

$memory = new Database();                          // in-memory (default)
$memory = new Database(':memory:');                // explicit
$file   = new Database('/data/analytics.duckdb');  // file-backed
$ro     = new Database('/data/analytics.duckdb', ['access_mode' => 'read_only']);
```

`Database` corresponds to the C `duckdb_database` handle. Configuration options
are passed as a constructor array — see [config.md](config.md).

An in-memory database lives as long as its `Database` object and is private to
it: two `new Database(':memory:')` instances do not share data.

## Connections

A `Database` is just the file/buffer manager. All work happens on a
`Connection` (the C `duckdb_connection`):

```php
$conn = $db->connect();
```

One database can have many connections, and this is the intended pattern for
concurrent work: **one connection per thread, fiber or unit of work**. Each
connection serializes its own statements internally, so you never have to lock
around a `Connection` yourself — but statements on the *same* connection do
execute one at a time.

## Closing

```php
$conn->close();        // idempotent; further use throws ConnectionException
$conn->isClosed();     // bool
```

`close()` is a logical close: the underlying DuckDB connection is released when
the object (and every object derived from it — results, pending queries) is
freed. In practice you can also just let objects go out of scope.

## File locking — one writer at a time

DuckDB takes an **exclusive lock on a database file the moment it is opened for
writing** (not on first write):

- A second **process** opening the same file fails immediately with an
  `IOException` (`Could not set lock on file ... Conflicting lock is held`).
- Within one process, multiple `Database` objects for the same **path string**
  are safe since 1.1.0: file-backed opens go through DuckDB's process-wide
  instance cache, so they share a single underlying instance and lock. The
  cache is keyed by the path string, so two different spellings of the same
  file (e.g. a relative and an absolute path) are *not* deduplicated — use
  one canonical path. A cached file instance stays open for the lifetime of
  the process; `:memory:` databases are exempt and remain private to each
  `Database` object.
- Read-only opens (`['access_mode' => 'read_only']`) do not take the write
  lock, so many processes can read the same file concurrently (while nobody
  writes).

```php
// Good: one Database, many connections
$db    = new Database('/data/app.duckdb');
$connA = $db->connect();
$connB = $db->connect();

// Also fine since 1.1.0: a second Database on the same path shares the
// cached instance (same process, same canonical path string)
$again = new Database('/data/app.duckdb');
```

## Interrupting and monitoring a connection

```php
$conn->interrupt();       // all running queries fail with InterruptedException

$progress = $conn->queryProgress();
// ['percentage' => 42.0, 'rowsProcessed' => 123456, 'totalRowsToProcess' => 290000]
// percentage is -1 when progress information is not available
```

Both are safe to call from another thread/fiber while a query is running, which
makes them the building blocks for watchdogs and progress bars around
[async queries](async.md).

## Introspecting a statement's tables

```php
$conn->getTableNames('SELECT * FROM users JOIN orders ON orders.user_id = users.id');
// ['users', 'orders']
```

This wraps `duckdb_get_table_names()`.

## Not exposed (and why)

| C API | Status |
|---|---|
| `duckdb_create_instance_cache()` / `duckdb_get_or_create_from_cache()` | Used internally since 1.1.0: file-backed `Database` opens share one instance per path string process-wide (see "File locking" above). Not exposed directly to PHP |
| `duckdb_connection_id()` | Not exposed; no PHP-facing use |
