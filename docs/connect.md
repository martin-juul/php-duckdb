# Connecting

Open a database, then create a connection to work with it. The PHP interface
wraps the C API's `duckdb_open()` / `duckdb_connect()` / `duckdb_disconnect()` /
`duckdb_close()`.

## Opening a database

```php
use DuckDB\Database;

$memory = new Database();                          // in-memory (default)
$memory = new Database(':memory:');                // explicit
$file   = new Database('/data/analytics.duckdb');  // file-backed
$ro     = new Database('/data/analytics.duckdb', ['access_mode' => 'read_only']);
```

`Database` corresponds to the C `duckdb_database` handle. Pass configuration
options in the constructor array; see [config.md](config.md).

An in-memory database lives as long as its `Database` object and is private to
it: two `new Database(':memory:')` instances do not share data.

## Connections

A `Database` manages the file or memory buffer. To execute statements, create a
`Connection`, which corresponds to the C `duckdb_connection`:

```php
$conn = $db->connect();
```

For concurrent work, create **one connection per thread, fiber or unit of
work** from the same database. Each connection serializes its own statements
internally, so you never have to lock around a `Connection` yourself. Statements
on the *same* connection execute one at a time.

## Closing

```php
$conn->close();        // idempotent; further use throws ConnectionException
$conn->isClosed();     // bool
```

`close()` marks the connection closed. The underlying DuckDB connection is
released when the object and every object derived from it, including results
and pending queries, are freed. You can also let these objects go out of scope.

## File locking — one writer at a time

DuckDB takes an **exclusive lock on a database file the moment it is opened for
writing** (not on first write):

- A second **process** opening the same file fails immediately with an
  `IOException` with `ErrorType::Io`
  (`Could not set lock on file ... Conflicting lock is held`).
- Within one process, multiple `Database` objects for the same **resolved path**
  are safe since 1.1.0: file-backed opens go through DuckDB's process-wide
  instance cache, so they share a single underlying instance and lock. DuckDB
  canonicalizes file paths before looking them up, so relative and absolute
  spellings that resolve to the same path share the instance. Their
  configurations must match. The cache holds weak references: the instance
  stays open while database wrappers, connections or objects derived from
  those connections own it. `:memory:` databases are exempt and remain private
  to each `Database` object.
- Read-only opens (`['access_mode' => 'read_only']`) take a shared read lock
  rather than the write lock. Many processes can read the same file concurrently,
  provided no process has it open for writing.

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

Both methods are safe to call from another thread/fiber while a query is
running. Use them to build watchdogs and progress bars around
[async queries](async.md).

## Introspecting a statement's tables

```php
$conn->getTableNames('SELECT * FROM users JOIN orders ON orders.user_id = users.id');
// ['users', 'orders']
```

This wraps `duckdb_get_table_names()`.

## Not exposed (and why)

| C API | Status |
| --- | --- |
| `duckdb_create_instance_cache()` / `duckdb_get_or_create_from_cache()` | Used internally since 1.1.0: file-backed `Database` opens share one instance per resolved path process-wide (see "File locking" above). Not exposed directly to PHP |
| `duckdb_connection_id()` | Not exposed; no PHP-facing use |
