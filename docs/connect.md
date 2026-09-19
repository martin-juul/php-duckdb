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
- Within one process, open **one `Database` object per file** and create all
  your connections from it. Do not construct a second `Database` for the same
  file in the same process — lock lifetime semantics make that fragile.
- Read-only opens (`['access_mode' => 'read_only']`) do not take the write
  lock, so many processes can read the same file concurrently (while nobody
  writes).

```php
// Good: one Database, many connections
$db    = new Database('/data/app.duckdb');
$connA = $db->connect();
$connB = $db->connect();
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
| `duckdb_create_instance_cache()` / `duckdb_get_or_create_from_cache()` | Not exposed. In PHP the idiom is simply to keep one `Database` object per path (a static, a container service, …). A future release may wrap the instance cache to make shared in-memory databases (`:memory:` named instances) possible |
| `duckdb_connection_id()` | Not exposed; no PHP-facing use |
