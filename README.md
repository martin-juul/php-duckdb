# duckdb — Native DuckDB driver for PHP

**duckdb is a PHP extension that puts a complete analytical SQL database inside
your PHP process.** It embeds [DuckDB](https://duckdb.org) — an in-process,
columnar OLAP engine — and exposes it as an idiomatic, fully typed PHP API.

**What it's for:** analytical workloads from PHP: aggregations and joins over
millions of rows, direct queries on CSV/Parquet/JSON files, ETL pipelines,
reporting and data-quality checks. These run without provisioning, connecting
to or operating a database server. Applications that currently shell out to a
CLI, export data to another system or load whole tables into arrays can perform
that work in the PHP process.

**What it is _not_ for:** high-concurrency OLTP, such as thousands of small
writes from many web requests. Use MySQL/PostgreSQL for that workload. DuckDB permits
writes from one process at a time and is designed for analytical scans and
bulk work.

Feature highlights:

- **Synchronous queries** with buffered or streaming results
- **Prepared statements** with positional (`?`, `$1`) and named (`$name`,
  `:name`) parameters
- **Transactions** with PDO-style helpers
- **Bulk inserts** via the Appender API
- **Arrow batches** through the [C Data Interface](docs/arrow.md), with native
  chunk conversion, schema inspection and batch appending
- **Standalone vectors**: typed native columns built from PHP and
  [assembled into chunks](docs/vector.md) for appending or Arrow export
- **Selection vectors**: pick, reorder and repeat [vector and chunk
  rows](docs/selection.md) natively, for example to filter a batch before
  appending it
- **Asynchronous execution** on background worker threads, with cancellation,
  progress reporting, Fiber suspension, coroutine-native **Swoole 6+**, **True
  Async**, **AMPHP v3** and **ReactPHP** integration, and event-loop support
  (completion stream / file descriptor)
- **Single-threaded async** via DuckDB's pending-execution API (no worker
  thread started by the extension — drives the query on the calling thread)
- **Typed error hierarchy** mirroring DuckDB's error categories
- **Typed inputs** for every DuckDB 1.5.6 type family, including nested values,
  catalog types, VARIANT and GEOMETRY. Results use documented
  [PHP mappings](docs/types.md), including exact DECIMAL/HUGEINT strings and
  temporal objects; those mappings have type-specific precision limitations.

The [runnable examples and API map](examples/README.md) cover the public API
and application workflows: order analytics, typed ingestion, Arrow batch
exchange and asynchronous reporting. Run them with `php tests/harness.php examples`.

## Comparison with alternatives

| Approach                        | Trade-offs                                                                                                                                                                            |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **PDO + ODBC**                  | Requires an ODBC driver and driver manager. Type conversion depends on the driver and PHP interface.                                                                                  |
| **DuckDB CLI**                  | Useful for scripts and batch jobs. The application manages subprocesses, input/output and error reporting.                                                                            |
| **FFI binding to libduckdb**    | Calls the C API without compiling a dedicated PHP extension. The binding must manage native memory and ownership, and PHP must have FFI enabled.                                      |
| **SQLite / MySQL / PostgreSQL** | Different database engines, rather than alternative DuckDB bindings. Choose according to workload and operational requirements.                                                       |
| **This driver**                 | Requires a compiled extension matching the PHP build. Provides prepared statements, typed binding, streaming, async execution and DuckDB error categories through native PHP classes. |

## How it works

```text
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

- **In-process**: queries execute on threads inside the PHP process — no server,
  socket, or network hop. A `Database` is a file handle (or pure memory); a
  `Connection` is a session on it.
- **Columnar → PHP**: results arrive as chunks of typed column vectors; the
  extension decodes them into PHP values row by row as you fetch. Buffered
  queries materialize up front; streaming queries keep memory flat no matter how
  large the result.
- **Explicit ownership**: every DuckDB handle has exactly one owner in the
  extension (RAII). Destruction order is always safe: a `Result` may outlive
  the `Connection` it came from without retaining a dangling handle.
- **Error reporting**: invalidated streams, mid-stream query errors, NUL bytes
  in SQL and pathological nesting all raise exceptions instead of silently
  truncating data or crashing the process (see _Safety guarantees_).

For application integration, see the
[PHP developer guide](docs/php-developer-guide.md). Check the
[compatibility matrix](docs/compatibility.md) and
[migration guide](docs/migrations.md) before upgrading.

## Requirements

- PHP **8.2+** (8.2–8.5 supported; enums are used in the public API)
- A C++17 compiler
- The DuckDB C library (`libduckdb` + `duckdb.h`), e.g. from
  <https://duckdb.org/docs/installation/> — this driver is developed and tested
  against **DuckDB v1.5.6**. Distribution builds also support **v1.5.5**;
  headers and library must match and provide the required C APIs.

Repository builds that vendor DuckDB use the
[patched SDK builder](packaging/duckdb/README.md). Its engine patch fixes
nullable bitpacking writes while retaining compression. External DuckDB
packages need an equivalent backport; see [compatibility](docs/compatibility.md).
The [patch inventory](packaging/duckdb/patches/README.md) records its purpose,
origin, build scope and removal criteria.

```text
/opt/duckdb
├── include/duckdb.h
└── lib/libduckdb.(so|dylib|lib)
```

## Installing with PIE

On Linux and macOS, install [PIE](https://php.github.io/pie/) and the
requirements above, plus the target PHP version's development tools (`phpize`
and `php-config`), `make`, and `autoconf`. Install the DuckDB C library
separately; PIE builds this extension against the supplied prefix:

```bash
pie install martinjuul/duckdb --with-duckdb=/opt/duckdb
php -r '$c = (new DuckDB\Database())->connect(); var_dump($c->query("SELECT 42 AS x")->fetchRow());'
```

On macOS, use `--with-duckdb=$(brew --prefix duckdb)`. PIE uses the PHP
installation that runs it and attempts to enable the extension automatically.
If that step fails, follow its instructions to add `extension=duckdb.so` to the
same PHP installation's configuration. Windows PIE binary distribution is not
provided yet. On Windows, use the matching x64 PHP 8.2–8.5 TS/NTS ZIP from a
release that includes Windows assets; see the
[Windows installation guide](packaging/windows/README.md).

## Building from source

```bash
php tests/harness.php doctor build --duckdb-dir=/opt/duckdb
make install          # copies duckdb.so into the PHP extension dir
echo "extension=duckdb.so" > $(php --ini | grep 'Scan for' | awk '{print $NF}')/99-duckdb.ini
php -r 'var_dump(DuckDB\version());'   # e.g. "v1.5.6"
```

On macOS use `--duckdb-dir=$(brew --prefix duckdb)`. On Windows use
`config.w32` with `phpize` from the PHP SDK.

### CLion and CMake setup

Opening this repository in CLion with its default Ninja profile works without
setting `DUCKDB_DIR` when a repository phpize/harness build already selected an
SDK in `config.nice`. CMake reads that file as configuration data and checks the
SDK's required APIs. A missing legacy cached `/opt/duckdb` default is migrated
when the IDE reloads the project. If an automatically discovered temporary SDK
disappears, the next configure retries discovery and automatic setup;
`DUCKDB_AUTO_BUILD=OFF` still prevents downloading or compilation.

On a fresh checkout, CMake automatically runs the shared SDK builder into
`<build directory>/duckdb-sdk`. Cold configuration downloads the pinned engine,
applies both repository patches and compiles it with the resource-aware worker
budget. The builder needs Python 3, CMake, C/C++ compilers, curl, tar, patch and
make; `DUCKDB_BUILD_JOBS` and `DUCKDB_SDK_CACHE_DIR` retain their usual meanings.
The first configure can therefore take substantially longer than later reloads.
Reloads verify automatically built SDK artifacts and source/patch pins; a pin
change can trigger a rebuild. Explicit or `config.nice` SDKs remain managed by
the build that supplied them.

For an existing SDK, pass `-DDUCKDB_DIR=/path/to/sdk` in the CLion profile or
set `DUCKDB_DIR` in its environment. Explicit paths are checked directly, with
no fallback to an arbitrary system engine. For offline configuration, use
`-DDUCKDB_AUTO_BUILD=OFF` and provide or reuse an existing SDK. SDK downloading
and compilation then remain disabled.

```bash
cmake -S . -B cmake-build-debug -G Ninja
cmake --build cmake-build-debug --parallel 2
```

CMake is an auxiliary Unix build for IDEs and analysis. Use the harness for
normal validation and `config.w32` for Windows PHP SDK builds.

For Packagist registration and tagged PIE releases, see the
[publishing instructions](packaging/README.md#publishing-to-packagist-for-pie).

Run the test suite:

```bash
LD_LIBRARY_PATH=/opt/duckdb/lib \
  php tests/harness.php --full --duckdb-dir=/opt/duckdb
```

## Docker images

Multi-arch images (`linux/amd64` + `linux/arm64`) with the extension
preinstalled are published to the GitHub Container Registry for every supported
PHP version, built from the `Dockerfile` in this repository:

```bash
docker run --rm ghcr.io/martin-juul/php-duckdb:8.4 \
  -r '$c = (new DuckDB\Database())->connect(); var_dump($c->query("SELECT 42 AS x")->fetchRow());'
```

Choose how closely to pin the extension and PHP versions:

| Tag examples             | Selection                                                                          |
| ------------------------ | ---------------------------------------------------------------------------------- |
| `8.4`, `php8.4`          | Moving build for PHP 8.4; also available for PHP 8.2, 8.3 and 8.5                  |
| `latest`                 | Moving build for the newest supported PHP version (currently 8.5)                  |
| `1.3.1-php8.4`           | Extension release 1.3.1 with PHP 8.4                                               |
| `1.3-php8.4`, `1-php8.4` | Moving extension minor/major release aliases with PHP 8.4                          |
| `1.3.1`                  | Extension release 1.3.1 with the newest supported PHP version                      |
| `1.3`, `1`               | Moving extension minor/major release aliases with the newest supported PHP version |

PHP-only aliases update on master pushes and stable release builds. Extension
release aliases are published on Git tags such as `v1.3.1` or `1.3.1`;
major/minor aliases follow the most recently published matching release build.
Prereleases such as `1.4.0-rc.1` publish only their full release tags, with and
without the PHP suffix, and leave stable aliases unchanged. Pull requests build
images without publishing tags.

CI builds and smoke-tests each architecture on a native runner. Publishing jobs
combine the successful AMD64 and ARM64 builds into the multi-architecture tags.

Images are based on `php:X.Y-cli-bookworm` and ship the matching libduckdb, so
the extension loads with no extra setup. To build locally instead:

```bash
docker build --build-arg PHP_VERSION=8.4 -t php-duckdb:8.4 .
```

ZTS variants for [FrankenPHP](https://frankenphp.dev) are published as
`8.4-frankenphp` and `8.5-frankenphp` (plus `latest-frankenphp`), based on
`dunglas/frankenphp` with the extension compiled against the embedded ZTS PHP —
ready for classic and worker mode out of the box:

```bash
docker run --rm --entrypoint php \
  ghcr.io/martin-juul/php-duckdb:8.5-frankenphp \
  -r 'var_dump(DuckDB\version());'
```

Every tag form above also has a `-frankenphp` variant for PHP 8.4 and 8.5, such
as `php8.4-frankenphp`, `1.3.1-php8.4-frankenphp`, `1.3-php8.4-frankenphp`,
`1.3.1-frankenphp` and `1-frankenphp`. Release-only FrankenPHP tags use PHP 8.5.

They are built from `Dockerfile.frankenphp` in this repository; see
[docs/frankenphp.md](docs/frankenphp.md) for worker-mode details.

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

`Database` owns the database file handle; `Connection` is a logical session. A
single database supports many connections — **use one connection per concurrent
unit of work** (see _Concurrency_ below).

## Executing queries

| Method                                                         | Returns   | Semantics                                                                                |
| -------------------------------------------------------------- | --------- | ---------------------------------------------------------------------------------------- |
| `Connection::query(string $sql): Result`                       | buffered  | Runs the SQL (multiple statements allowed; the **last** statement's result is returned). |
| `Connection::queryStreaming(string $sql): Result`              | streaming | Constant-memory, chunk-at-a-time fetching.                                               |
| `Connection::execute(string $sql, array $params = []): Result` | buffered  | Prepare + bind + execute in one call.                                                    |
| `Connection::prepare(string $sql): Statement`                  | —         | Reusable prepared statement.                                                             |
| `Connection::queryAsync(string $sql): PendingQuery`            | —         | Executes on a background worker thread.                                                  |
| `Connection::queryPending(string $sql): PendingQuery`          | —         | Single-threaded async (polling mode).                                                    |

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

Results are **forward-only**. A `Result` provides exactly one iterator, and
iterators cannot be rewound. This follows the underlying streaming protocol
and prevents the same result from being consumed twice.

### Streaming & the one-stream rule

`queryStreaming()` / `Statement::executeStreaming()` keep memory constant for
arbitrarily large results. DuckDB allows **one open streaming result per
connection**: starting any new query (or appender) on the same connection
invalidates the previous stream. This driver detects that situation and throws a
`DuckDB\Exception` instead of silently truncating your data — buffered `query()`
results are not affected. For concurrent streams, open one connection per
stream.

A query that fails _mid-stream_, for example on a cast error millions of rows
into a result, throws the usual typed exception while fetching. A stream never
silently truncates. Fetching again rethrows the error; the connection stays
usable.

## Arrow batches

These APIs are under development in this checkout and are not included in the
released 1.3.1 archive.

Fetch columnar batches from buffered or streaming results, inspect their schemas,
and convert them to reusable native chunks. Lossless Arrow conversion is enabled
by default. Explicitly disabling it makes exports of HUGEINT, UHUGEINT, BIT and
TIMETZ throw instead of losing data. The bundled SDK preserves geometry CRS
without requiring an explicit transaction. VARIANT export is unsupported.
See [conversion semantics](docs/arrow.md#type-conversion-settings).

```php
$source->query('SET arrow_lossless_conversion = true');
$result = $source->queryStreaming('SELECT i::INTEGER AS id FROM range(10000) t(i)');
$schema = $result->arrowSchema()->toArray();
while (($arrow = $result->fetchArrowChunk()) !== null) {
    $chunk = $destination->dataChunkFromArrow($arrow);
    $appender->appendChunk($chunk);
}
```

Create the destination table and appender on a separate connection before the
loop. Conversion consumes the Arrow batch; native chunks can be appended,
decoded with `toRows()` and exported again. Native address import/export uses
the Arrow C Data Interface. Schema exports copy, array exports move, and
callers own release of native exports. These APIs work without PHP FFI;
FFI is optional for native address exchange. See [Arrow conversion](docs/arrow.md)
for ownership, mixed row fetching and supported conversion semantics, and the
[runnable example](examples/arrow.php).

## Vectors

These APIs are under development in this checkout and are not included in the
released 1.3.1 archive.

Build typed native columns from PHP values and assemble them into chunks.
Writes convert input like typed binding, and a rejected batch leaves the vector
unchanged.

```php
$ids = $conn->createVector('BIGINT', 3);
$tags = $conn->createVector('VARCHAR[]', 3);
$ids->setValues($conn, [1, 2, 3]);
$tags->setValues($conn, [['new'], [], null]);
$appender->appendChunk(DuckDB\DataChunk::fromVectors(['id' => $ids, 'tags' => $tags], 3));
```

A chunk holds at most `DuckDB\vectorSize()` rows and copies its vectors. See
[vectors](docs/vector.md) and the [runnable example](examples/vectors.php).

Selections copy chosen rows of a vector or chunk without decoding them. Pass a
reusable `DuckDB\SelectionVector` or a plain list of row indices:

```php
$chunk = $conn->dataChunkFromArrow($result->fetchArrowChunk());
$valid = array_keys(array_filter($chunk->vector(1)->toArray(), fn($v) => $v !== null));
$appender->appendChunk($chunk->select($valid));
```

See [selection vectors](docs/selection.md) and the
[runnable example](examples/selection.php).

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

Parameter types come from the SQL context. In `INSERT INTO t VALUES (?)`, the
table schema supplies the type; in `WHERE i = ?::INTEGER`, the cast supplies it.
An unconstrained parameter (`SELECT ?`) cannot be typed until a value is bound,
which is also how the DuckDB C API behaves.

### PHP → DuckDB binding types

| PHP value                            | DuckDB type                                                   |
| ------------------------------------ | ------------------------------------------------------------- |
| `null`                               | `NULL`                                                        |
| `bool`                               | `BOOLEAN`                                                     |
| `int`                                | `BIGINT`                                                      |
| `float`                              | `DOUBLE`                                                      |
| `string`                             | `VARCHAR` (binary-safe)                                       |
| `string` via `Statement::bindBlob()` | `BLOB`                                                        |
| `DateTimeInterface`                  | `TIMESTAMP` (microsecond precision, UTC)                      |
| `DuckDB\Interval`                    | `INTERVAL`                                                    |
| `list<mixed>`                        | `LIST` (element types must be uniform; `null` elements adapt) |
| `array<string, mixed>`               | `STRUCT`                                                      |

For an explicit type, use the native typed input classes:

```php
$stmt->bindValue('amount', new DuckDB\Decimal('12.345', precision: 18, scale: 2));
$conn->execute('SELECT ?', [new DuckDB\ListValue([], DuckDB\Integer::class)]);
```

Wrappers also work in Appender and nested inputs. DuckDB resolves and casts them
on the consuming connection at execution time. See the
[complete typed input contract](docs/value.md) and [roadmap](docs/roadmap.md).

Bound values never enter the SQL text, so binding is always safe against SQL
injection. Always prefer parameters over string interpolation.

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

DuckDB uses optimistic concurrency: a conflicting concurrent write fails one of
the transactions with a `TransactionException` during a write or commit. Roll
back the failed transaction and retry the unit of work. Transaction state is
owned by DuckDB. Nesting `beginTransaction()` or committing without an active
transaction raises a `TransactionException` (code
`ErrorType::Transaction`). Plain SQL (`BEGIN`/`COMMIT`/`ROLLBACK`) works
identically.

## Bulk inserts: the Appender

The appender loads rows without executing a prepared INSERT for each row.
For bulk loads, benchmark it against prepared statements with your data:

```php
$appender = $conn->appender('ducks');            // (table, schema?, catalog?)
foreach ($rows as [$id, $name, $hatched]) {
    $appender->appendRow([$id, $name, $hatched]);
}
$appender->flush();   // optional; happens on close/destruct
$appender->close();   // idempotent
```

To use column defaults, build each row one value at a time:

```php
$appender->beginRow();
$appender->append(42);            // next column value
$appender->appendDefault();       // use the column's DEFAULT
$appender->append('Quackers');
$appender->endRow();
```

A native submission or flush failure blocks further appends until `clear()`
discards all buffered rows and any partial row. It cannot undo rows already
flushed; use a transaction when the entire batch must roll back. Conversion
errors before submission leave the appender usable. See
[Appender recovery](docs/appender.md#flushing-and-failure-semantics).

## Async execution

```php
$pending = $conn->queryAsync('SELECT count(*) FROM huge_table');

$pending->isReady();     // non-blocking completion check
$result = $pending->await();      // block this thread until done
$pending->cancel();      // interrupt the query (-> InterruptedException)
```

- **`queryAsync()` / `Statement::executeAsync()`** run the query on a detached
  worker thread. Worker threads never touch PHP state; completion is signalled
  through a socket pair (loopback TCP on Windows): `PendingQuery::getStream()`
  returns a PHP stream usable with `stream_select()`, `getFd()` the raw
  descriptor (a duplicate — closing it is safe) for compatible event loops. On
  Windows, raw handles are Winsock sockets requiring `closesocket()`; prefer PHP
  streams.
- **`PendingQuery::suspend()`** waits for completion without blocking the
  scheduler: natively integrated with Swoole 6+, True Async, AMPHP v3 and
  ReactPHP (below), with a plain _Fiber_ protocol as the fallback for custom
  schedulers.
- **`Connection::queryPending()`** runs on the calling thread. Each `isReady()`
  call executes one slice of DuckDB's task graph without spawning a worker
  thread. Polling performs the work rather than busy-waiting. This mode suits
  `dl()`-restricted and other specialized SAPIs.
- A `PendingQuery` result can be consumed exactly once (`await()` or
  `suspend()`).

`suspend()` integrates with the async runtimes below. When several are
installed,
the first available runtime handles the call in this order: **Swoole 6+ → True
Async → AMPHP → ReactPHP → plain fiber protocol**. Frameworks usually ship
alone,
so this overlap is uncommon.

### Swoole 6+

When ext-swoole **6.0+** is loaded, calling `PendingQuery::suspend()` inside a
Swoole coroutine yields that coroutine on the query's completion descriptor.
Swoole's scheduler resumes it when DuckDB's worker thread finishes. The event
loop can therefore serve other coroutines while the query runs. Detection is
automatic and requires no configuration, build flags or link-time dependency on
Swoole. OpenSwoole uses different namespaces and constants, so it is not
engaged;
`suspend()` there falls back to the fiber contract.

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

- Use `queryAsync()` + `suspend()` (or `Statement::executeAsync()`) for anything
  non-trivial. The synchronous `query()` executes on the calling thread and
blocks the whole scheduler while it runs. Millisecond queries may be acceptable;
  heavy scans keep other coroutines waiting.
- Polling mode (`queryPending()`) also works inside coroutines: `suspend()`
  drives the query in slices and yields between them.
- `cancel()` from a peer coroutine works and surfaces as
  `DuckDB\InterruptedException` in the suspended one.
- One connection per coroutine. A `Connection` serializes its queries by design;
  connections to the same `Database` are cheap.

See `examples/swoole.php` for a complete runnable version.

### True Async

[True Async](https://true-async.github.io/en/) is the async php-src fork with
`Async\spawn()` / `Async\await()` and structured concurrency backed by libuv.
Under a True Async PHP binary, calling `PendingQuery::suspend()` inside an
`Async\` coroutine waits in the libuv reactor on the query's completion
descriptor in worker mode. In polling mode, it yields via `Async\delay()`
between
DuckDB task slices. The scheduler thread stays free for other coroutines while
the query runs. Detection is automatic and requires no configuration or build
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
- Cancelling a coroutine (`$coroutine->cancel()`) is cooperative and propagates
  into `suspend()`: the in-flight DuckDB query is interrupted and
  `\Cancellation` (e.g. `Async\AsyncCancellation`) escapes the suspending call.
- One connection per coroutine — DuckDB interrupts are connection-level, so a
  cancelled query would take down sibling queries sharing its connection.
- Once the runtime is engaged, the fork treats the root context as a coroutine
  too, so top-level `suspend()` works as well.

True Async is a full php-src fork rather than a loadable extension: build this
driver with the fork's `phpize` and run it with the fork's `php` binary (see
`examples/true_async.php`, and `tests/050_true_async.phpt` which runs when the
test suite itself runs under that binary).

### AMPHP v3

[AMPHP](https://amphp.org/) v3 runs on the Revolt event loop in userland, so it
needs no linked library or additional extension. When `Revolt\EventLoop` is
loadable, `PendingQuery::suspend()` suspends the current fiber on the loop. In
worker modes, a loop watcher fires when the query's completion stream becomes
readable. In polling mode, the query runs in slices with `Amp\delay()`-style
yields between them. Other fibers keep running while the query executes.
Detection is automatic.

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

- Use `queryAsync()` + `suspend()` for anything non-trivial; the synchronous
  `query()` blocks the calling thread while it runs.
- Query errors surface inside the awaiting fiber as ordinary `DuckDB\Exception`
  subclasses.
- At the top level of a script, `suspend()` works too: the main context waits by
  running the event loop, exactly like `Future::await()`.
- One connection per fiber — a `Connection` serializes its queries by design;
  connections to the same `Database` are cheap.

AMPHP v3 requires PHP 8.1+; install it with `composer require amphp/amp`. See
`examples/amphp.php` for a complete runnable version, and `tests/051_amphp.phpt`
(activated via `DUCKDB_AMPHP_AUTOLOAD` or a project `vendor/autoload.php`).

### ReactPHP (react/async v4+)

[ReactPHP](https://reactphp.org/) is likewise userland: with `react/async` **v4
or later** loadable, `PendingQuery::suspend()` awaits a promise on the
`React\EventLoop` that resolves when the query's completion stream becomes
readable (worker modes), or yields via `React\Async\delay()` between DuckDB task
slices (polling mode). The loop keeps serving other events while the query runs.
Detection is automatic; react/async **v3 is deliberately not engaged** (its
fiber model differs — `suspend()` there falls back to the plain fiber contract).

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

- Use `queryAsync()` + `suspend()` for anything non-trivial; the synchronous
  `query()` blocks the calling thread while it runs.
- Cancelling the surrounding `async()` promise is cooperative: the awaited
  promise rejects, the in-flight DuckDB query is interrupted, and a
  `RuntimeException` escapes `suspend()`.
- At the top level of a script, `suspend()` works too: react/async's scheduler
  fiber runs the loop while the main context waits.
- One connection per fiber — a `Connection` serializes its queries by design;
  connections to the same `Database` are cheap.

Install with `composer require react/async:^4.0`. See `examples/reactphp.php`
for a complete runnable version, and `tests/052_reactphp.phpt` (activated via
`DUCKDB_REACTPHP_AUTOLOAD` or a project `vendor/autoload.php`).

### Progress & interruption

```php
$conn->interrupt();          // cancel ALL running queries on this connection
$progress = $conn->queryProgress();
// ['percentage' => 41.5, 'rowsProcessed' => 415000, 'totalRowsToProcess' => 1000000]
```

`queryProgress()` is thread-safe and may be polled from another fiber/thread
while a query runs (`percentage` is `-1.0` when no estimate is available).

## Error handling

Every DuckDB failure throws a subclass of `DuckDB\Exception` chosen by DuckDB's
machine-readable error category. `getCode()` returns the `DuckDB\ErrorType`
backing value; `getErrorType()` returns the enum case.

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

| Exception              | Typical causes                                                                                                                                                     |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ConnectionException`  | connect failures, using a closed connection                                                                                                                        |
| `ParserException`      | SQL syntax errors                                                                                                                                                  |
| `BinderException`      | unknown identifiers, type resolution, unknown parameter names                                                                                                      |
| `CatalogException`     | missing tables/schemas/columns                                                                                                                                     |
| `ConstraintException`  | constraint violations                                                                                                                                              |
| `ConversionException`  | failed casts, out-of-range, division by zero                                                                                                                       |
| `TransactionException` | transaction conflicts, invalid transaction state                                                                                                                   |
| `IOException`          | file system, network, HTTP, extension loading — including `Database` open failures with DuckDB's `IO Error:` prefix (unwritable path, single-writer lock conflict) |
| `InterruptedException` | `interrupt()` / `PendingQuery::cancel()`                                                                                                                           |
| `InternalException`    | DuckDB-internal errors (please report upstream)                                                                                                                    |

Programmer errors raise native PHP errors instead: `ValueError` for invalid
arguments (bad parameter index, wrong value count, non-uniform lists), `Error`
for protocol violations (e.g. `Appender::append()` without `beginRow()`).

## Type mapping (DuckDB → PHP)

| DuckDB                                   | PHP                                                                         |
| ---------------------------------------- | --------------------------------------------------------------------------- |
| `BOOLEAN`                                | `bool`                                                                      |
| `TINYINT`–`BIGINT`, `UTINYINT`–`UBIGINT` | `int` when it fits, else exact decimal `string`                             |
| `HUGEINT`, `UHUGEINT`                    | `int` when it fits, else exact decimal `string`                             |
| `BIGNUM`                                 | exact decimal `string`                                                      |
| `FLOAT`, `DOUBLE`                        | `float`                                                                     |
| `DECIMAL`                                | exact decimal `string` (no precision loss)                                  |
| `VARCHAR`, `ENUM`                        | `string`                                                                    |
| `BLOB`, `GEOMETRY`                       | `string` (binary)                                                           |
| `BIT`                                    | `string` (text of `0` and `1` digits)                                       |
| `UUID`                                   | `string` (canonical)                                                        |
| `DATE`                                   | `DateTimeImmutable` (midnight UTC)                                          |
| `TIMESTAMP`, `TIMESTAMP_S/MS/NS/TZ`      | `DateTimeImmutable` (UTC)                                                   |
| `TIME`, `TIME_TZ`, `TIME_NS`             | `string`                                                                    |
| `INTERVAL`                               | `DuckDB\Interval` (`getMonths()/getDays()/getMicros()`, `JsonSerializable`) |
| `LIST`, `ARRAY`                          | `list`                                                                      |
| `STRUCT`                                 | `array<string, mixed>`                                                      |
| `MAP`                                    | assoc array (list of `['key'=>…,'value'=>…]` for non-scalar keys)           |
| `UNION`                                  | the member value                                                            |
| `VARIANT`                                | `string` (JSON rendering)                                                   |
| `NULL`                                   | `null`                                                                      |

Non-finite temporal values (`infinity`) are returned as strings. Integers that
exceed PHP's integer range and exact decimals are returned as strings so no
value ever loses precision silently.

## Configuration

```php
$db = new Database('/data/shop.duckdb', [
    'access_mode'  => 'read_only',
    'threads'      => 4,
    'memory_limit' => '1GB',
]);
```

Any
[DuckDB configuration option](https://duckdb.org/docs/stable/configuration/overview)
is accepted (`string|int|float|bool` values). Unknown options fail with
`DuckDB\Exception` code `ErrorType::InvalidConfiguration` (42).

## Connection lifecycle

```php
$conn->close();      // idempotent; further use throws ConnectionException
$conn->isClosed();   // bool
```

Internal reference counts keep resources alive while they are in use. A
`Database` may be freed while its connections remain alive. A `Connection` may
be freed or closed while its async queries finish safely in the background. In
long-running processes, close each connection when you are done with it.

Other introspection:

```php
$conn->getTableNames('SELECT * FROM orders JOIN customers USING (customer_id)');
// ['orders', 'customers'] — query analysis, not a catalog listing

DuckDB\version();        // 'v1.5.6' (linked library version)
duckdb_version();        // legacy alias
```

## Safety guarantees

The test suite covers the following input checks and resource-lifecycle rules:

- **No silent SQL truncation**: SQL text or identifiers containing NUL bytes are
  rejected with `ValueError` (the DuckDB C API is NUL-terminated, so an embedded
  NUL would otherwise truncate the statement mid-flight).
- **No silent data truncation**: invalidated streams (one-stream rule) and
  mid-stream query failures both throw; you never get a partial result disguised
  as a complete one.
- **Bounded nesting**: ordinary PHP↔DuckDB value conversion has a depth limit
  of 512. Typed declarations, snapshots and conversion use a limit of 64.
  Excessive nesting raises `ValueError`.
- **Object lifecycle**: native resource classes are `final`, uncloneable and
  reject `serialize()`/`unserialize()`. Typed wrappers also reject cloning and
  serialization. `DuckDB\Value` permits custom subclasses; the dedicated type
  classes are `final`. `DuckDB\Interval` is a separate value class.
- **Closed-resource discipline**: using a closed connection (queries, binding,
  streaming, appending) throws `ConnectionException`; closing twice is a no-op.
- **Memory checks**: the full harness runs Valgrind over the PHPT suite, apart
  from tests explicitly marked `--VALGRIND-SKIP--`. Validation results and
  their scope are recorded in [typed input coverage](docs/typed-coverage.md).

## Concurrency & thread-safety model

- Each `Connection` serializes its own DuckDB calls with an internal mutex — you
  cannot corrupt state by mixing sync/async use of one connection, but queries
  on one connection never run _in parallel_. For parallel queries, open one
  connection per query (`$db->connect()` is cheap).
- File-backed databases support one writer process. A second process opening
  the same file for writing fails with `IOException` (`ErrorType::Io`,
  "Could not set lock on file ..."). Multiple processes may open the database
  read-only when no process has it open for writing. Within one process,
  `Database` objects opened with the same resolved file path share the native
  database handle. Destroying one wrapper does not close the handle while
  another wrapper or connection still owns it. In-memory databases remain
  private to each `Database` object.
- Worker threads only execute DuckDB calls; all PHP/zval access happens on the
  request thread. Safe under ZTS, `parallel`, FrankenPHP, etc.
- DuckDB interrupt is connection-scoped: `PendingQuery::cancel()` on a threaded
  query interrupts the whole connection (cancelling a finished query is a no-op
  and never disturbs newer work).

## FrankenPHP

The extension is ZTS-safe and works under [FrankenPHP](https://frankenphp.dev)
in both classic and worker mode — verified against FrankenPHP's embedded ZTS PHP
in CI, including a concurrent worker-mode smoke test and a graceful-shutdown
check. Because FrankenPHP embeds a **ZTS** PHP, the extension must be built
against the same PHP version with ZTS enabled. The quickest start is the
prebuilt image (`ghcr.io/martin-juul/php-duckdb:8.5-frankenphp`, see
[Docker images](#docker-images)); to build your own, the
`dunglas/frankenphp:builder-*` images contain everything needed. See
[docs/frankenphp.md](docs/frankenphp.md) for the build recipe and worker-mode
semantics (persistent `:memory:` databases per worker thread, async queries in
workers, safe shutdown), and [examples/frankenphp.php](examples/frankenphp.php)
for a runnable worker script.

## Development

### Test harness

`tests/harness.php` is the single entry point for every verification stage —
never run `run-tests.php` or Valgrind by hand:

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
- **build** runs phpize/configure/make, incrementally; `--rebuild` forces a
  clean build.
- **unit** runs the `.phpt` suite in parallel (`--jobs=N`, `--filter=PATTERN`).
- **examples** runs every `examples/*.php`; all must exit 0.
- **valgrind** re-runs the suite under Memcheck with Zend's allocator disabled;
  definite leaks or memory errors fail the stage. glibc/DuckDB thread-lifecycle
  noise is suppressed via `tests/duckdb.supp`; tests too slow for Memcheck carry
  a `--VALGRIND-SKIP--` marker.
- **stress** runs `tests/stress/*.php`: memory-stability and
  concurrency/cancellation loops.

Exit codes: `0` all green, `1` a stage failed, `2` environment problem, `3`
build failure. `--junit=FILE` emits a JUnit report for CI.

### Layout

`duckdb.stub.php` defines every signature and supplies the IDE/static-analysis
stubs. Generate `duckdb_arginfo.h` using the generator from PHP 8.2 source,
the minimum supported PHP version, and commit the generated file:

```bash
php /path/to/php-8.2-src/build/gen_stub.php --force-regeneration duckdb.stub.php
```

Never edit `duckdb_arginfo.h` by hand; regenerate it in the same commit as the
stub change. Newer PHP generators can emit macros unavailable on PHP 8.2,
even when the stub signatures themselves are compatible.

Layout:

```text
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
