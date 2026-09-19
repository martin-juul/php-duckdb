# duckdb — Native DuckDB driver for PHP

**duckdb is a PHP extension that puts a complete analytical SQL database
inside your PHP process.** It embeds [DuckDB](https://duckdb.org) — an
in-process, columnar OLAP engine — and exposes it as an idiomatic, fully
typed PHP API.

**What it's for:** analytical workloads from PHP — aggregations and joins
over millions of rows, querying CSV/Parquet/JSON files directly, ETL
pipelines, reporting, data-quality checks — without provisioning,
connecting to, or operating a database server. If your PHP app today
shells out to a CLI, exports to another system, or loads whole tables into
arrays just to crunch them, this is the missing piece.

**What it is *not* for:** high-concurrency OLTP (thousands of small
writes from many web requests) — use MySQL/PostgreSQL for that; DuckDB is
single-writer by design and shines at reads and bulk work.

Feature highlights:

- **Synchronous queries** with buffered or streaming results
- **Prepared statements** with positional (`?`, `$1`) and named
  (`$name`, `:name`) parameters
- **Transactions** with PDO-style helpers
- **Bulk inserts** via the Appender API
- **Asynchronous execution** on background worker threads, with
  cancellation, progress reporting, Fiber suspension, coroutine-native
  **Swoole 6+**, **True Async**, **AMPHP v3** and **ReactPHP**
  integration, and event-loop support (completion stream / file
  descriptor)
- **Single-threaded async** via DuckDB's pending-execution API (no worker
  threads — drives the query in slices on the calling thread)
- **Typed error hierarchy** mirroring DuckDB's error categories
- **Full type coverage**: nested types (LIST/STRUCT/MAP/ARRAY/UNION),
  DECIMAL/HUGEINT without precision loss, temporal types as
  `DateTimeImmutable`, ENUM, UUID, BIT, BLOB, INTERVAL

## Why not the alternatives?

| Approach | Why it falls short |
|---|---|
| **PDO + ODBC** | Extra driver-manager layer to install and configure; ODBC is row-oriented and stringly typed, so you lose DuckDB's type fidelity (decimals, nested types) and pay conversion overhead on every row. |
| **Shelling out to the `duckdb` CLI** | Process spawn per query, results parsed from text output, no prepared statements, no streaming, no error taxonomy — fine for a cron job, wrong for an application. |
| **FFI binding to libduckdb** | No build step, but you hand-roll memory management and ownership in userland; per-call FFI overhead is significant exactly where a driver is hottest (per-chunk, per-value). |
| **SQLite / MySQL / PostgreSQL** | Different tool: row stores built for OLTP. For analytical scans DuckDB's vectorized columnar engine is typically 10–100× faster, and it reads Parquet/CSV natively. |
| **This driver** | Native C++ extension over DuckDB's stable C API: columnar chunks are decoded straight into PHP values with full type fidelity, constant-memory streaming, real async, and DuckDB's exact error categories. |

## How it works

```
PHP script
   │  DuckDB\{Database, Connection, Statement, Result, PendingQuery, Appender}
   ▼
duckdb extension (C++ / Zend API)        ← this project
   │  thin, ownership-explicit wrapper
   ▼
libduckdb (stable C API)
   │  vectorized execution, columnar chunks
   ▼
your data (memory / .duckdb file / Parquet / CSV / …)
```

- **In-process**: queries execute on threads inside the PHP process — no
  server, socket, or network hop. A `Database` is a file handle (or pure
  memory); a `Connection` is a session on it.
- **Columnar → PHP**: results arrive as chunks of typed column vectors;
  the extension decodes them into PHP values row by row as you fetch.
  Buffered queries materialize up front; streaming queries keep memory
  flat no matter how large the result.
- **Explicit ownership**: every DuckDB handle has exactly one owner in
  the extension (RAII), so destruction order is always safe — a `Result`
  may outlive the `Connection` it came from without dangling.
- **Fail loud**: invalidated streams, mid-stream query errors, NUL bytes
  in SQL, and pathological nesting all raise exceptions instead of
  silently truncating data or crashing the process (see
  *Safety guarantees*).

## Requirements

- PHP **8.2+** (8.2–8.5 supported; enums are used in the public API)
- A C++17 compiler
- The DuckDB C library (`libduckdb` + `duckdb.h`), e.g. from
  <https://duckdb.org/docs/installation/> — this driver is developed and
  tested against **DuckDB v1.5.x**

```
/opt/duckdb
├── include/duckdb.h
└── lib/libduckdb.(so|dylib|lib)
```

## Building & installing

```bash
phpize
./configure --with-duckdb=/opt/duckdb
make -j$(nproc)
make install          # copies duckdb.so into the PHP extension dir
echo "extension=duckdb.so" > $(php --ini | grep 'Scan for' | awk '{print $NF}')/99-duckdb.ini
php -r 'var_dump(DuckDB\version());'   # e.g. "v1.5.5"
```

On macOS use `--with-duckdb=$(brew --prefix duckdb)`. On Windows use
`config.w32` with `phpize` from the PHP SDK.

Run the test suite:

```bash
LD_LIBRARY_PATH=/opt/duckdb/lib \
  php run-tests.php -q -d extension=$PWD/modules/duckdb.so tests/
```

## Docker images

Multi-arch images (`linux/amd64` + `linux/arm64`) with the extension
preinstalled are published to the GitHub Container Registry for every
supported PHP version, built from the `Dockerfile` in this repository:

```bash
docker run --rm ghcr.io/martin-juul/php-duckdb:8.4 \
  -r '$c = (new DuckDB\Database())->connect(); var_dump($c->query("SELECT 42 AS x")->fetchRow());'
```

Tags: `8.2`, `8.3`, `8.4`, `8.5` (moving tags, rebuilt on every master
push), `latest` (alias for the newest PHP version), and
`<release>-php<X.Y>` for tagged releases (e.g. `1.0.0-php8.4`). Images are
based on `php:X.Y-cli-bookworm` and ship the matching libduckdb, so the
extension loads with no extra setup. To build locally instead:

```bash
docker build --build-arg PHP_VERSION=8.4 -t php-duckdb:8.4 .
```

## Quick start

```php
<?php
use DuckDB\Database;

$db   = new Database(':memory:');        // or new Database('/data/shop.duckdb')
$conn = $db->connect();

$conn->query('CREATE TABLE ducks (id INTEGER, name VARCHAR, hatched DATE)');
$conn->execute('INSERT INTO ducks VALUES (?, ?, ?)', [1, 'Quackers', '2024-05-01']);

$result = $conn->query('SELECT * FROM ducks');
foreach ($result as $row) {              // Result is IteratorAggregate
    echo $row['name'], "\n";
}
```

`Database` owns the database file handle; `Connection` is a logical session.
A single database supports many connections — **use one connection per
concurrent unit of work** (see *Concurrency* below).

## Executing queries

| Method | Returns | Semantics |
|---|---|---|
| `Connection::query(string $sql): Result` | buffered | Runs the SQL (multiple statements allowed; the **last** statement's result is returned). |
| `Connection::queryStreaming(string $sql): Result` | streaming | Constant-memory, chunk-at-a-time fetching. |
| `Connection::execute(string $sql, array $params = []): Result` | buffered | Prepare + bind + execute in one call. |
| `Connection::prepare(string $sql): Statement` | — | Reusable prepared statement. |
| `Connection::queryAsync(string $sql): PendingQuery` | — | Executes on a background worker thread. |
| `Connection::queryPending(string $sql): PendingQuery` | — | Single-threaded async (polling mode). |

### Results

```php
$result = $conn->query('SELECT id, name FROM ducks');

$row  = $result->fetchRow();                       // assoc array, null at end
$row  = $result->fetchRow(DuckDB\FetchMode::Num);  // positional array
$rows = $result->fetchAll();                       // all remaining rows
$val  = $result->fetchColumn();                    // first column of next row

$result->rowCount();        // buffered results only
$result->rowsChanged();     // rows written by INSERT/UPDATE/DELETE
$result->statementType();   // 'SELECT', 'INSERT', 'CREATE', ...
$result->columns();         // [['name' => 'id', 'type' => 'INTEGER'], ...]
$result->columnCount();
$result->columnName(0);
$result->columnType(0);
```

Results are **forward-only** and consumed by iteration: a `Result` hands out
exactly one iterator, and iterators cannot be rewound. This mirrors the
underlying streaming protocol and prevents accidental double-consumption.

### Streaming & the one-stream rule

`queryStreaming()` / `Statement::executeStreaming()` keep memory constant for
arbitrarily large results. DuckDB allows **one open streaming result per
connection**: starting any new query (or appender) on the same connection
invalidates the previous stream. This driver detects that situation and
throws a `DuckDB\Exception` instead of silently truncating your data —
buffered `query()` results are not affected. For concurrent streams, open
one connection per stream.

A query that fails *mid-stream* (e.g. a cast error millions of rows in)
surfaces as the usual typed exception while fetching — a stream never
silently truncates. The error is sticky (fetching again re-throws) and the
connection stays usable.

## Prepared statements

```php
$stmt = $conn->prepare('INSERT INTO ducks VALUES (?, ?, ?)');
foreach ($rows as [$id, $name, $hatched]) {
    $stmt->execute([$id, $name, $hatched]);
}

// Named parameters ($name / :name), with or without the prefix:
$stmt = $conn->prepare('SELECT * FROM ducks WHERE name = $name');
$stmt->bindValue('name', 'Quackers');
$row = $stmt->execute()->fetchRow();

// Metadata:
$stmt->parameterCount();       // number of parameters
$stmt->parameterName(1);       // 'name', or '1' for positional
$stmt->parameterType(1);       // 'VARCHAR' — resolved from context when possible
$stmt->statementType();        // 'SELECT', 'INSERT', ...
$stmt->columnCount();          // result columns without executing
$stmt->columnName(0);
$stmt->columnType(0);

$stmt->clearBindings();        // reset all bound values
```

Parameter types are resolved from context: in `INSERT INTO t VALUES (?)` the
parameter type comes from the table schema; in `WHERE i = ?::INTEGER` from
the cast. A completely unconstrained parameter (`SELECT ?`) cannot be typed
until a value is bound — this mirrors the DuckDB C API.

### PHP → DuckDB binding types

| PHP value | DuckDB type |
|---|---|
| `null` | `NULL` |
| `bool` | `BOOLEAN` |
| `int` | `BIGINT` |
| `float` | `DOUBLE` |
| `string` | `VARCHAR` (binary-safe) |
| `string` via `Statement::bindBlob()` | `BLOB` |
| `DateTimeInterface` | `TIMESTAMP` (microsecond precision, UTC) |
| `DuckDB\Interval` | `INTERVAL` |
| `list<mixed>` | `LIST` (element types must be uniform; `null` elements adapt) |
| `array<string, mixed>` | `STRUCT` |

Binding is always safe against SQL injection — values never touch the SQL
text. Always prefer parameters over string interpolation.

## Transactions

```php
$conn->beginTransaction();
try {
    $conn->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $conn->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
    $conn->commit();
} catch (DuckDB\Exception $e) {
    $conn->rollBack();
    throw $e;
}
```

DuckDB uses optimistic concurrency: a conflicting concurrent write fails one
of the transactions with a `TransactionException` at commit time — retry the
unit of work. Transaction state is owned by DuckDB; nesting
`beginTransaction()` or committing without an active transaction raises a
`TransactionException` (code `ErrorType::Transaction`). Plain SQL
(`BEGIN`/`COMMIT`/`ROLLBACK`) works identically.

## Bulk inserts: the Appender

For bulk loads, the appender is an order of magnitude faster than prepared
INSERTs (it bypasses the query engine and writes row groups directly):

```php
$appender = $conn->appender('ducks');            // (table, schema?, catalog?)
foreach ($rows as [$id, $name, $hatched]) {
    $appender->appendRow([$id, $name, $hatched]);
}
$appender->flush();   // optional; happens on close/destruct
$appender->close();   // idempotent
```

Or build rows piecemeal — handy with column defaults:

```php
$appender->beginRow();
$appender->append(42);            // next column value
$appender->appendDefault();       // use the column's DEFAULT
$appender->append('Quackers');
$appender->endRow();
```

A DuckDB-level failure invalidates the appender (DuckDB cannot recover a
partially written row); discard it and create a new one. PHP-side conversion
errors (`ValueError`) are raised *before* anything is appended, so the
appender stays usable.

## Async execution

```php
$pending = $conn->queryAsync('SELECT count(*) FROM huge_table');

$pending->isReady();     // non-blocking completion check
$result = $pending->await();      // block this thread until done
$pending->cancel();      // interrupt the query (-> InterruptedException)
```

- **`queryAsync()` / `Statement::executeAsync()`** run the query on a
  detached worker thread. Worker threads never touch PHP state; completion
  is signalled through a pipe: `PendingQuery::getStream()` returns a PHP
  stream usable with `stream_select()`, `getFd()` the raw descriptor (a
  duplicate — closing it is safe) for `ext-uv`/`uv_poll` style loops.
- **`PendingQuery::suspend()`** waits for completion without blocking the
  scheduler: natively integrated with Swoole 6+, True Async, AMPHP v3 and
  ReactPHP (below), with a plain *Fiber* protocol as the fallback for
  custom schedulers.
- **`Connection::queryPending()`** is the single-threaded variant: no worker
  thread is spawned; each `isReady()` call executes one slice of the DuckDB
  task graph on the calling thread, so polling *is* the work (not a
  busy-wait). Ideal inside `dl()`-restricted or otherwise exotic SAPIs.
- A `PendingQuery` result can be consumed exactly once (`await()` or
  `suspend()`).

`suspend()` integrates natively with the async runtimes documented below.
When several are installed at once (rare — frameworks usually ship alone),
the first available runtime claims the call, in this order: **Swoole 6+ →
True Async → AMPHP → ReactPHP → plain fiber protocol**.

### Swoole 6+

When ext-swoole **6.0+** is loaded, `PendingQuery::suspend()` called inside
a Swoole coroutine yields the *coroutine* on the query's completion
descriptor instead of blocking: Swoole's scheduler resumes it when DuckDB's
worker thread finishes, so the event loop keeps serving other coroutines
while the query runs. Detection is automatic — no configuration, no build
flags, and no link-time dependency on Swoole. OpenSwoole is deliberately
not engaged (its namespace and constants differ; `suspend()` there falls
back to the fiber contract).

```php
use function Swoole\Coroutine\run;

run(function () use ($db) {
    $cids = [];
    foreach ($queries as $label => $sql) {
        $cids[] = Swoole\Coroutine::create(function () use ($db, $sql, $label, &$results) {
            // one connection per coroutine: connections serialize their queries
            $results[$label] = $db->connect()->queryAsync($sql)->suspend()->fetchAll();
        });
    }
    Swoole\Coroutine::join($cids);   // queries ran concurrently
});
```

Rules of thumb under Swoole:

- Use `queryAsync()` + `suspend()` (or `Statement::executeAsync()`) for
  anything non-trivial. The synchronous `query()` executes on the calling
  thread and blocks the whole scheduler while it runs — fine for
  millisecond queries, wrong for heavy scans.
- Polling mode (`queryPending()`) also works inside coroutines:
  `suspend()` drives the query in slices and yields between them.
- `cancel()` from a peer coroutine works and surfaces as
  `DuckDB\InterruptedException` in the suspended one.
- One connection per coroutine. A `Connection` serializes its queries by
  design; connections to the same `Database` are cheap.

See `examples/swoole.php` for a complete runnable version.

### True Async

[True Async](https://true-async.github.io/en/) — the async php-src fork
(`Async\spawn()` / `Async\await()` / structured concurrency backed by libuv)
— gets the same treatment. When the driver runs under a True Async PHP
binary and `PendingQuery::suspend()` is called inside an `Async\` coroutine,
the coroutine parks in the libuv reactor on the query's completion
descriptor (worker mode) or yields via `Async\delay()` between DuckDB task
slices (polling mode). The scheduler thread stays free for other coroutines
while the query runs. Detection is automatic — no configuration, no build
flags.

```php
$coroutines = [];
foreach ($queries as $label => $sql) {
    $coroutines[$label] = Async\spawn(function () use ($db, $sql) {
        // one connection per coroutine: connections serialize their queries
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll();
    });
}
$results = Async\await_all_or_fail($coroutines);   // queries ran concurrently
```

Rules of thumb under True Async (same as under Swoole):

- Use `queryAsync()` + `suspend()` for anything non-trivial; the synchronous
  `query()` blocks the calling thread while it runs.
- Cancelling a coroutine (`$coroutine->cancel()`) is cooperative and
  propagates into `suspend()`: the in-flight DuckDB query is interrupted and
  `\Cancellation` (e.g. `Async\AsyncCancellation`) escapes the suspending
  call.
- One connection per coroutine — DuckDB interrupts are connection-level, so
  a cancelled query would take down sibling queries sharing its connection.
- Once the runtime is engaged, the fork treats the root context as a
  coroutine too, so top-level `suspend()` works as well.

True Async is a full php-src fork rather than a loadable extension: build
this driver with the fork's `phpize` and run it with the fork's `php`
binary (see `examples/true_async.php`, and `tests/050_true_async.phpt`
which runs when the test suite itself runs under that binary).

### AMPHP v3

[AMPHP](https://amphp.org/) v3 runs on the Revolt event loop — a userland
framework, so nothing to link and no extension to install. Wherever
`Revolt\EventLoop` is loadable, `PendingQuery::suspend()` suspends the
current fiber on the loop: in worker modes a loop watcher fires when the
query's completion stream becomes readable; in polling mode the query is
driven in slices with `Amp\delay()`-style yields between them. Other fibers
keep running while the query executes. Detection is automatic.

```php
$futures = [];
foreach ($queries as $label => $sql) {
    $futures[$label] = Amp\async(function () use ($db, $sql) {
        // one connection per fiber: connections serialize their queries
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll();
    });
}
$results = Amp\Future\await($futures);   // queries ran concurrently
```

Rules of thumb under AMPHP (same spirit as under Swoole):

- Use `queryAsync()` + `suspend()` for anything non-trivial; the
  synchronous `query()` blocks the calling thread while it runs.
- Query errors surface inside the awaiting fiber as ordinary
  `DuckDB\Exception` subclasses.
- At the top level of a script, `suspend()` works too: the main context
  waits by running the event loop, exactly like `Future::await()`.
- One connection per fiber — a `Connection` serializes its queries by
  design; connections to the same `Database` are cheap.

AMPHP v3 requires PHP 8.1+; install it with `composer require amphp/amp`.
See `examples/amphp.php` for a complete runnable version, and
`tests/051_amphp.phpt` (activated via `DUCKDB_AMPHP_AUTOLOAD` or a project
`vendor/autoload.php`).

### ReactPHP (react/async v4+)

[ReactPHP](https://reactphp.org/) is likewise userland: with
`react/async` **v4 or later** loadable, `PendingQuery::suspend()` awaits a
promise on the `React\EventLoop` that resolves when the query's completion
stream becomes readable (worker modes), or yields via
`React\Async\delay()` between DuckDB task slices (polling mode). The loop
keeps serving other events while the query runs. Detection is automatic;
react/async **v3 is deliberately not engaged** (its fiber model differs —
`suspend()` there falls back to the plain fiber contract).

```php
use function React\Async\{async, await};

$promises = [];
foreach ($queries as $label => $sql) {
    $promises[$label] = async(function () use ($db, $sql) {
        // one connection per fiber: connections serialize their queries
        return $db->connect()->queryAsync($sql)->suspend()->fetchAll();
    })();
}
$results = array_map(await(...), $promises);   // queries ran concurrently
```

Rules of thumb under ReactPHP:

- Use `queryAsync()` + `suspend()` for anything non-trivial; the
  synchronous `query()` blocks the calling thread while it runs.
- Cancelling the surrounding `async()` promise is cooperative: the awaited
  promise rejects, the in-flight DuckDB query is interrupted, and a
  `RuntimeException` escapes `suspend()`.
- At the top level of a script, `suspend()` works too: react/async's
  scheduler fiber runs the loop while the main context waits.
- One connection per fiber — a `Connection` serializes its queries by
  design; connections to the same `Database` are cheap.

Install with `composer require react/async:^4.0`. See
`examples/reactphp.php` for a complete runnable version, and
`tests/052_reactphp.phpt` (activated via `DUCKDB_REACTPHP_AUTOLOAD` or a
project `vendor/autoload.php`).

### Progress & interruption

```php
$conn->interrupt();          // cancel ALL running queries on this connection
$progress = $conn->queryProgress();
// ['percentage' => 41.5, 'rowsProcessed' => 415000, 'totalRowsToProcess' => 1000000]
```

`queryProgress()` is thread-safe and may be polled from another fiber/thread
while a query runs (`percentage` is `-1.0` when no estimate is available).

## Error handling

Every DuckDB failure throws a subclass of `DuckDB\Exception` chosen by
DuckDB's machine-readable error category. `getCode()` returns the
`DuckDB\ErrorType` backing value; `getErrorType()` returns the enum case.

```php
use DuckDB\{Exception, CatalogException, ConstraintException, ErrorType};

try {
    $conn->query('SELECT * FROM missing');
} catch (CatalogException $e) {
    // table/view/schema problems
} catch (ConstraintException $e) {
    // PK/UNIQUE/FK/NOT NULL/CHECK violations
} catch (Exception $e) {
    $e->getErrorType();            // ?DuckDB\ErrorType
    ErrorType::tryFrom($e->getCode());
}
```

| Exception | Typical causes |
|---|---|
| `ConnectionException` | connect failures, using a closed connection |
| `ParserException` | SQL syntax errors |
| `BinderException` | unknown identifiers, type resolution, unknown parameter names |
| `CatalogException` | missing tables/schemas/columns |
| `ConstraintException` | constraint violations |
| `ConversionException` | failed casts, out-of-range, division by zero |
| `TransactionException` | transaction conflicts, invalid transaction state |
| `IOException` | file system, network, HTTP, extension loading — including `Database` open failures with DuckDB's `IO Error:` prefix (unwritable path, single-writer lock conflict) |
| `InterruptedException` | `interrupt()` / `PendingQuery::cancel()` |
| `InternalException` | DuckDB-internal errors (please report upstream) |

Programmer errors raise native PHP errors instead: `ValueError` for invalid
arguments (bad parameter index, wrong value count, non-uniform lists),
`Error` for protocol violations (e.g. `Appender::append()` without
`beginRow()`).

## Type mapping (DuckDB → PHP)

| DuckDB | PHP |
|---|---|
| `BOOLEAN` | `bool` |
| `TINYINT`–`BIGINT`, `UTINYINT`–`UBIGINT` | `int` |
| `HUGEINT`, `UHUGEINT` | `int` when it fits, else exact decimal `string` |
| `FLOAT`, `DOUBLE` | `float` |
| `DECIMAL` | exact decimal `string` (no precision loss) |
| `VARCHAR`, `ENUM` | `string` |
| `BLOB`, `BIT`, `GEOMETRY` | `string` (binary) |
| `UUID` | `string` (canonical) |
| `DATE` | `DateTimeImmutable` (midnight UTC) |
| `TIMESTAMP`, `TIMESTAMP_S/MS/NS/TZ` | `DateTimeImmutable` (UTC) |
| `TIME`, `TIME_TZ`, `TIME_NS` | `string` |
| `INTERVAL` | `DuckDB\Interval` (`getMonths()/getDays()/getMicros()`, `JsonSerializable`) |
| `LIST`, `ARRAY` | `list` |
| `STRUCT` | `array<string, mixed>` |
| `MAP` | assoc array (list of `['key'=>…,'value'=>…]` for non-scalar keys) |
| `UNION` | the member value |
| `VARIANT` | `string` (JSON rendering) |
| `NULL` | `null` |

Non-finite temporal values (`infinity`) are returned as strings. Integers
that exceed 64 bits and exact decimals are returned as strings so no value
ever loses precision silently.

## Configuration

```php
$db = new Database('/data/shop.duckdb', [
    'access_mode'  => 'read_only',
    'threads'      => 4,
    'memory_limit' => '1GB',
]);
```

Any [DuckDB configuration
option](https://duckdb.org/docs/stable/configuration/overview) is accepted
(`string|int|float|bool` values). Unknown options fail with
`DuckDB\Exception` code `ErrorType::InvalidConfiguration` (42).

## Connection lifecycle

```php
$conn->close();      // idempotent; further use throws ConnectionException
$conn->isClosed();   // bool
```

Objects are reference-counted internally: a `Database` may be freed while
its connections live on, and a `Connection` may be freed (or closed) while
async queries derived from it finish safely in the background. Close a
connection when you are done with it in long-running processes.

Other introspection:

```php
$conn->getTableNames('SELECT * FROM orders JOIN customers USING (customer_id)');
// ['orders', 'customers'] — query analysis, not a catalog listing

DuckDB\version();        // 'v1.5.5' (linked library version)
duckdb_version();        // legacy alias
```

## Safety guarantees

The driver fails loudly rather than corrupting or crashing, and these
guarantees are covered by the test suite:

- **No silent SQL truncation**: SQL text or identifiers containing NUL
  bytes are rejected with `ValueError` (the DuckDB C API is
  NUL-terminated, so an embedded NUL would otherwise truncate the
  statement mid-flight).
- **No silent data truncation**: invalidated streams (one-stream rule) and
  mid-stream query failures both throw; you never get a partial result
  disguised as a complete one.
- **No process crashes from hostile input**: PHP↔DuckDB value conversion
  is depth-limited (512 levels, like PHP's JSON) — pathologically nested
  arrays raise `ValueError` instead of overflowing the C stack.
- **No invalid objects**: driver classes are `final`, uncloneable, and
  refuse `serialize()`/`unserialize()` — an object created without its
  constructor would have no valid internal state.
- **Closed-resource discipline**: using a closed connection (queries,
  binding, streaming, appending) throws `ConnectionException`; closing
  twice is a no-op.
- **Memory safety**: the suite is Valgrind-clean (no invalid reads/writes,
  no leaks) across normal and error paths.

## Concurrency & thread-safety model

- Each `Connection` serializes its own DuckDB calls with an internal mutex —
  you cannot corrupt state by mixing sync/async use of one connection, but
  queries on one connection never run *in parallel*. For parallel queries,
  open one connection per query (`$db->connect()` is cheap).
- File-backed databases are **single-writer across processes**: the first
  process to open the file holds an exclusive lock, and a `new Database()`
  for the same file from another process fails immediately with
  `IOException` (`ErrorType::Io`, "Could not set lock on file ...").
  Within one process a second `new Database()` for the same file succeeds
  (POSIX fcntl locks are per-process) and sees everything committed so far —
  but note that POSIX drops *all* of a process's locks on a file when *any*
  of its descriptors for that file is closed, so once that second handle is
  destroyed the primary handle's cross-process lock is gone too. If you rely
  on the single-writer guarantee, keep exactly one `Database` per file per
  process.
- Worker threads only execute DuckDB calls; all PHP/zval access happens on
  the request thread. Safe under ZTS, `parallel`, FrankenPHP, etc.
- DuckDB interrupt is connection-scoped: `PendingQuery::cancel()` on a
  threaded query interrupts the whole connection (cancelling a finished
  query is a no-op and never disturbs newer work).

## FrankenPHP

The extension is ZTS-safe and works under [FrankenPHP](https://frankenphp.dev)
in both classic and worker mode — verified against FrankenPHP's embedded
ZTS PHP in CI, including a concurrent worker-mode smoke test and a
graceful-shutdown check. Because FrankenPHP embeds a **ZTS** PHP, the
extension must be built against the same PHP version with ZTS enabled;
the `dunglas/frankenphp:*-builder` images contain everything needed. See
[docs/frankenphp.md](docs/frankenphp.md) for the build recipe and
worker-mode semantics (persistent `:memory:` databases per worker thread,
async queries in workers, safe shutdown), and
[examples/frankenphp.php](examples/frankenphp.php) for a runnable worker
script.

## Development

### Test harness

`tests/harness.php` is the single entry point for every verification
stage — never run `run-tests.php` or Valgrind by hand:

```bash
php tests/harness.php                 # doctor + build + unit + examples
php tests/harness.php --full          # ... plus valgrind + stress
php tests/harness.php --quick         # doctor + unit only
php tests/harness.php unit --filter='03*'   # just the type tests
php tests/harness.php valgrind        # Memcheck over the .phpt suite
php tests/harness.php --full --junit=report.xml
```

Stages:

- **doctor** verifies the toolchain (PHP ≥ 8.2, phpize, libduckdb,
  run-tests.php, valgrind) with actionable errors.
- **build** runs phpize/configure/make, incrementally; `--rebuild` forces
  a clean build.
- **unit** runs the `.phpt` suite in parallel (`--jobs=N`,
  `--filter=PATTERN`).
- **examples** runs every `examples/*.php`; all must exit 0.
- **valgrind** re-runs the suite under Memcheck with Zend's allocator
  disabled; definite leaks or memory errors fail the stage. glibc/DuckDB
  thread-lifecycle noise is suppressed via `tests/duckdb.supp`; tests too
  slow for Memcheck carry a `--VALGRIND-SKIP--` marker.
- **stress** runs `tests/stress/*.php`: memory-stability and
  concurrency/cancellation loops.

Exit codes: `0` all green, `1` a stage failed, `2` environment problem,
`3` build failure. `--junit=FILE` emits a JUnit report for CI.

### Layout

`duckdb.stub.php` is the single source of truth for every signature (it
doubles as IDE/static-analysis stubs). `duckdb_arginfo.h` is generated from
it and committed:

```bash
php /path/to/php-src/build/gen_stub.php duckdb.stub.php
```

Never edit `duckdb_arginfo.h` by hand; regenerate it in the same commit as
the stub change.

Layout:

```
duckdb.cpp          Database, Connection, module lifecycle
src/statement.cpp   Statement (prepare/bind/metadata/execute)
src/result.cpp      Result, ResultIterator, type decoding
src/pending.cpp     PendingQuery, worker threads, polling mode, suspend() dispatch
src/suspend_*.cpp   Runtime integrations: Swoole, True Async, AMPHP, ReactPHP
src/appender.cpp    Appender
src/values.cpp      PHP <-> DuckDB value conversion, error taxonomy
tests/              .phpt suite + harness.php (the test entry point)
tests/stress/       Long-running memory/concurrency stress scripts
examples/           Runnable example scripts
```

## Author

Created and maintained by **Martin Juul Christiansen**
([juul.xyz](https://juul.xyz), <code@juul.xyz>).

## License

MIT (see LICENSE).
