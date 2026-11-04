# Migrations

## Upgrade 1.5.0 → 2.0.0

Extension **2.0.0** requires DuckDB **2.0.x** and no longer builds or runs
against DuckDB 1.5.x. The pinned engine moves from DuckDB 1.5.6 to **2.0.0**,
built by the [patched SDK builder](../packaging/duckdb/README.md) with four
local engine patches. PHP **8.2–8.5** remain supported. No PHP signatures
change, and no PHP APIs are removed or newly deprecated. The major version
marks the engine change and the behavior changes below; most come from DuckDB
2.0 itself, which the extension documents rather than emulates.

1. Record `PHP_VERSION`, `phpversion('duckdb')` and `DuckDB\version()` from
   the deployed process. Retain the previous extension artifact and its
   matching engine library, or the previous immutable image digest.
2. Back up file databases with connections closed, and test against a
   disposable copy. DuckDB 2.0 opens files written by 1.5, but a file that
   DuckDB 2.0 writes at its default storage version can no longer be opened by
   DuckDB 1.5.x. To keep a file readable by 1.5 during the transition, attach
   it with `ATTACH 'file.duckdb' AS db (STORAGE_VERSION 'v1.5.0')`.
3. Pin extension **2.0.0** and DuckDB **2.0.0** together. Rebuild `duckdb.so`
   for the target PHP ABI against matching `duckdb.h` and `libduckdb`. Update
   Composer requirements to `"ext-duckdb": "^2.0"`. Docker users move from
   `1.5.0`, `1.5` and `1` tags to `2.0.0`, `2.0` and `2`; the moving PHP tags,
   such as `8.4` and `latest`, follow the new release.
4. Reinstall DuckDB engine extensions loaded with SQL `INSTALL`/`LOAD`. Binaries
   downloaded for DuckDB 1.5 do not load into DuckDB 2.0.
5. Run the application's tests, paying attention to transactions, interleaved
   statements on one connection, PHP scalar functions and COPY formats, then
   restart long-running PHP processes.

### Transactions

DuckDB 2.0 aborts an explicit transaction on **any** error inside it,
including parser, binder and catalog errors that DuckDB 1.5 let the
transaction survive. Every later statement in that transaction fails with a
`TransactionException` ("Current transaction is aborted (please ROLLBACK)")
until it is rolled back. DuckDB's `default_transaction_invalidation_policy`
setting controls this. Setting it to `'SYNTACTIC_ERRORS_DO_NOT_INVALIDATE'`,
through SQL `SET` or the `Database` configuration array, keeps the transaction
usable after parser and binder errors.

`Connection::commit()` now throws a `TransactionException` (code 10,
`ErrorType::Transaction`) when DuckDB has aborted the transaction, for any
cause: "Transaction was aborted, so nothing was committed; it has been rolled
back". Extension 1.5.0 returned normally while DuckDB discarded the work. The
connection is outside the transaction afterwards. The first `rollBack()` that
follows such a `commit()` succeeds without doing anything, so the usual
pattern rethrows the commit error rather than a "no transaction is active"
error:

```php
$conn->beginTransaction();
try {
    applyChanges($conn);
    $conn->commit(); // throws TransactionException if DuckDB aborted the transaction
} catch (Throwable $e) {
    $conn->rollBack();
    throw $e;
}
```

Any other statement on the connection ends that allowance, so a later
`rollBack()` without a transaction still throws.

A raw `COMMIT` statement keeps DuckDB's behavior: it rolls back an aborted
transaction and reports success. Use `commit()` when the application must
know whether its changes were saved. `commit()` first runs `EXPLAIN SELECT 1`
to ask DuckDB whether the transaction is still valid. That statement appears
in DuckDB's query log, but not in profiler output.

Typed-value conversion errors, from `Vector::set()`, `Value::toString()`,
typed parameters and typed appends, still leave the caller's transaction
usable, as in 1.5. One exception: an unknown type name inside an explicit
transaction is a catalog error and aborts it.

### Open results

As in 1.5, a statement started on a connection supersedes that connection's
open streaming result or unfinished pending query. DuckDB 2.0 would refuse the
new statement with `RESOURCE_IN_USE`; the extension ends the open result itself
first, so code that interleaves statements keeps working.

- Rows and metadata that the superseded result already holds stay readable,
  including the rest of its current chunk, `columns()` and
  `statementType()`. Its next native fetch throws the existing "This streaming
  result was invalidated by a newer query…" `DuckDB\Exception`, which reports
  `getErrorType() === ErrorType::Invalid` (code 0), as in 1.5.
- A superseded pending query of any kind throws "COPY statement superseded by
  another operation on its connection" when awaited; the wording also applies
  to pending queries that run no COPY.
- `bindValue()`, `clearBindings()`, `getTableNames()`, `dataChunkFromArrow()`,
  `DataChunk::toArrow()`, `registerCopyToFunction()` and
  `registerScalarFunction()` start no statement and leave open results alone.
  Unlike 1.5, `bindValue()` no longer cancels an undriven pending query that
  runs a PHP COPY format.
- Appender rows and batches (`endRow()`, `appendRow()`, `appendChunk()`,
  `appendArrow()`), `flush()`, `close()` and destruction supersede an open
  result and keep their rows, including rows that DuckDB flushes internally.
- `queryAsync()` and `executeAsync()` supersede the open result from their
  worker thread, too.

Superseding an unfinished statement that may write follows DuckDB 2.0: under
autocommit its writes roll back; inside an explicit transaction DuckDB aborts
the transaction, and the superseding statement itself throws "Current
transaction is aborted (please ROLLBACK)". An undriven
`queryPending('INSERT …')` is such a statement. A streaming
`INSERT … RETURNING` is not: its writes finish before its first row. Drive a
pending write to completion with `await()` before using its connection again,
or run it on its own connection.

Registering a scalar function while a result is open resolves its types
against committed types in the database's default catalog. Otherwise it
resolves them on the registering connection, as in 1.5.

### Changed engine behavior

- PHP scalar functions: `ScalarFunction::bind()` and
  `Expression::getReturnType()` receive argument types **after** DuckDB's
  implicit cast to the declared parameter types. For a `BIGINT` parameter,
  `php_fn(40 + 2)` now reports `BIGINT`; 1.5 reported `INTEGER`.
- A multi-statement `query()` whose later statement has a syntax error runs
  the statements before it, then throws the `ParserException`. Inside an
  explicit transaction, the error aborts the transaction.
- VARIANT columns export to Arrow as the `arrow.parquet.variant` extension
  type and round-trip through `Connection::dataChunkFromArrow()`. 1.5 refused
  the export.
- `Result::statementType()` keeps reporting the real statement type, such as
  `INSERT` or `SELECT`, for prepared, streaming, pending and asynchronous
  results. DuckDB 2.0 reports `EXECUTE` there; the extension corrects it. SQL
  `EXECUTE` statements still report `EXECUTE`.
- The prepared query `SELECT typeof($value), COALESCE($value::VARCHAR, 'NULL')`
  no longer raises an `InternalException`, but `typeof($value)` reports
  `UNKNOWN` for a VARCHAR binding. See
  [prepared statements](prepared.md#native-query-shape-limitation).

### Types and errors

- Result columns of the new `TIMESTAMPTZ_NS` type decode to UTC
  `DateTimeImmutable` values with microsecond precision and report the type
  name `TIMESTAMPTZ_NS`, like the other timestamp types. There is no dedicated
  typed class; declare input with `new DuckDB\Value('TIMESTAMPTZ_NS', …)`, or
  bind a `DateTimeInterface` and let DuckDB cast it.
- `DuckDB\ErrorType` gains `DataCorruption` (43) and `ResourceInUse` (44).
  Exhaustive `match` expressions over `ErrorType` need the new cases.
- BIGNUM and VARIANT decoding is verified for DuckDB 2.0.x, including VARIANT's
  new nanosecond time-zone timestamp tag; other engine versions still raise a
  decoding error for these two types.
- A COPY or scalar handler exception raised while `PendingQuery::isReady()` or
  a runtime's `suspend()` steps a query is now the `getPrevious()` cause of the
  error that `await()` or `suspend()` throws. 1.5 dropped it once the stepping
  call returned.

### Threading

PHP code still runs only on the request thread. On a connection with a
registered PHP COPY format or scalar function, DuckDB's work for buffered
statements, pending queries and streaming fetches now runs on a helper thread
that belongs to the connection, while the request thread runs the PHP
callbacks it receives. The helper starts on first use, parks between
statements and ends with the connection. Each `PendingQuery::isReady()` call on
such a connection lets the query run for about 1 ms. Connections without PHP
formats or functions execute as before.

- `queryAsync()` now refuses to run inside a COPY handler on its own
  connection ("Connection is busy executing a COPY handler"), like
  `executeAsync()`.
- Freeing a prepared statement of the busy connection inside a COPY or scalar
  handler is deferred until the handler returns. 1.5 freed it immediately.

### Engine patches and builds

All packaging recipes and Docker images use the patched DuckDB 2.0.0 SDK. Its
five patches fix nullable bitpacking writes, Arrow conversion transactions and
geometry CRS, C API COPY format binding, a DuckDB 2.0.0 hash-join gather that
fails a `LEFT JOIN` bringing in a fixed-size `ARRAY` column with unmatched rows
and correlated scalar subqueries returning an `ARRAY`, and reads of the
uninitialised value of an unset optional index during checkpoints. Builds
against an external or unpatched DuckDB 2.0.0 library keep these defects; see
the [patch inventory](../packaging/duckdb/patches/README.md). DuckDB 2.0
release builds otherwise require AVX2 (x86-64-v3); the SDK builders keep a
portable CPU baseline.

### Rollback

Stop processes using the database, restore the previous PHP extension and
matching DuckDB 1.5.6 library or image, and restart. Restore the backup for
any database file that DuckDB 2.0 wrote at its default storage version:
DuckDB 1.5.x cannot open it, and replacing binaries does not reverse that
change.

## Upgrade 1.4.1 → 1.5.0

DuckDB remains pinned to **1.5.6**, with the repository's patched SDK.
PHP **8.2–8.5** remain supported. Rebuild or install extension **1.5.0** for
the target PHP ABI and restart persistent PHP workers. Replace the extension
and bundled engine library together; retain the previous pair or immutable
image for rollback.

This minor release adds callback, catalog and validation APIs:

- [PHP scalar functions](scalar_functions.md) with bind, per-worker init and
  batch execution callbacks on the request thread, and
  `ScalarExpressionFunction` for inspecting bound argument types and folding
  constant arguments.
- [Table-function metadata](table_functions.md) through
  `Connection::getTableFunctions()`.
- [Custom logging](logging.md) that buffers native log entries in bounded
  database-wide storage for explicit PHP draining.
- Connection-free [UTF-8 checks](api.md#functions) with
  `DuckDB\isValidUtf8()` and `DuckDB\checkUtf8()`.

One behavior changes: read-side type strings now include a geometry column's
CRS. `Result::columns()`, `Result::columnType()`,
`Statement::parameterType()`, `Statement::columnType()`,
`DataChunk::columns()` and `Vector::type()` report `GEOMETRY('OGC:CRS84')`
where 1.4.1 reported `GEOMETRY`. Code that compares these strings with
`'GEOMETRY'` must account for the CRS; columns without a CRS still report
`GEOMETRY`. See [compatibility](compatibility.md).

No PHP APIs are removed or newly deprecated. Statements that call PHP scalar
functions cannot run through the background-thread `queryAsync()` and
`executeAsync()`; run them synchronously, streaming or through
`queryPending()`.

## Upgrade 1.4.0 → 1.4.1

This patch release keeps the 1.4.0 PHP API and DuckDB **1.5.6**. It joins
asynchronous query workers before module unload, so their thread-local cleanup
cannot race extension shutdown. Destroying a `PendingQuery` remains
nonblocking. No application code changes are required; replace the extension
and bundled engine together and restart persistent PHP workers.

For development memory checks on x86_64, this release also includes a
[Valgrind 3.27.1 stack-unwind patch](../tests/valgrind-3.27.1-fiber-unwind.patch)
and [build instructions](../README.md#test-harness). The patch changes the
validator, not the installed PHP extension.

## Upgrade 1.3.1 → 1.4.0

DuckDB remains pinned to **1.5.6**, with the repository's patched SDK required
for the documented Arrow conversion and deterministic custom COPY TO binding.
PHP **8.2–8.5** remain supported. Rebuild or install extension **1.4.0** for
the target PHP ABI and restart persistent PHP workers. Replace the extension
and bundled engine library together; retain the previous pair or immutable
image for rollback.

This minor release adds typed input, columnar and export APIs:

- [Typed values](value.md) for explicit SQL declarations in prepared binding
  and appending, with connection-aware `Value::toString()` display text.
- [Arrow schemas and chunks](arrow.md), including native C Data Interface
  import/export, result batch fetching and conversion to reusable native chunks.
- [Owned vectors](vector.md), [selection vectors](selection.md) and
  [data chunks](data_chunk.md) for typed column construction, copying and
  selection without decoding every row into PHP arrays.
- [Batch appending](appender.md#arrow-and-native-batches) with
  `appendArrow()` and `appendChunk()`.
- [Custom COPY TO formats](copy.md) with PHP handlers that receive borrowed
  result chunks on the request thread.

Existing query, prepared-statement, row appender and asynchronous entry points
remain available. No PHP APIs are removed or newly deprecated. Applications
can adopt the new APIs incrementally. Arrow conversion consumes its Arrow
input; native chunks remain reusable. COPY callback chunks are valid only
inside `CopyToWriter::write()`. Review the linked ownership rules before
retaining or exchanging native addresses.

`Appender::clear()` now allows explicit recovery after a native submission or
flush failure. It discards buffered rows and any partial row before reuse;
rows already flushed remain in the table. Use a transaction when the entire
batch must roll back. A failed appender rejects appends and flushes until
cleared; `close()` on a failed appender discards pending data. See
[appender failure semantics](appender.md#flushing-and-failure-semantics).

Update an application's Composer extension requirement to `^1.4.0` when it
uses these APIs. Source builds need matching headers and library from the
[patched SDK builder](../packaging/duckdb/README.md); an unpatched upstream
DuckDB 1.5.6 library does not provide all documented behavior. Test application
queries, conversions, batch appends and custom formats against a disposable
copy of production data before deployment. The compatibility tables record
configured coverage and validation evidence, including optional runtime skips.

For rollback, stop processes using the database, restore the previous PHP
extension and matching engine library or image, and restart. Restore the
database backup if required by its state; replacing binaries does not reverse
database changes. The engine pin is unchanged from 1.3.1.

## Upgrade 1.3.0 → 1.3.1

DuckDB remains pinned to **1.5.6**. No PHP APIs are removed or newly
deprecated. Rebuild or install the matching 1.3.1 package and restart
persistent PHP workers. Windows ZIP packages are available starting with
this release.

### Moving to a Windows package

Use the [Windows installation guide](../packaging/windows/README.md) to match
PHP minor version, TS/NTS, x64 architecture, and compiler/runtime. Replace the
PHP extension and bundled DuckDB DLL together, retaining the previous pair
for rollback, and restart every persistent PHP worker after installation.
Existing releases are immutable: Windows builds are available only for
releases whose assets include them.

No PHP method signatures change. `PendingQuery::getStream()` now wraps a
Windows socket that works with `stream_select()`. Raw handles returned by
`getFd()` are independently owned Winsock sockets on Windows, not CRT file
descriptors: native callers must use `closesocket()`, not `_close()`. Prefer
the PHP stream interface unless the event loop explicitly accepts Winsock
handles. See [async ownership](async.md#event-loops-completion-descriptor).

## Upgrade 1.2.2 → 1.3.0

DuckDB remains pinned to **1.5.6**. No PHP signatures or return types change,
and no PHP APIs are removed or newly deprecated. Cancelling or dropping a
partially executed polling query now interrupts and drains its work before
releasing the handle, preventing retained executor/database allocations.
Cleanup also preserves newer queries on the same connection.

Update your extension dependency to **1.3.0**, rebuild or install the package
for the target PHP ABI, and restart persistent PHP workers. Re-run application
cancellation and connection-reuse tests. The backup, paired-binary rollback,
and engine-extension guidance below still applies; this release does not
require an additional DuckDB engine upgrade from 1.2.2.

## Upgrade 1.2.1 → 1.2.2

The pinned engine changes from DuckDB 1.5.5 to 1.5.6. Existing query,
prepared-statement, appender, and asynchronous PHP entry points remain
unchanged. There are no PHP API removals or new PHP deprecations in this patch.
The new engine setting is available through the existing
[configuration API](config.md#commonly-used-options).
Consult [compatibility](compatibility.md)
for the C API stabilization and deprecation audit, and [the API](api.md) for
available PHP methods.

1. Record `PHP_VERSION`, `phpversion('duckdb')`, and `DuckDB\version()` from the
   deployed process. Retain the previous extension artifact and its matching
   engine library, or the previous immutable image digest.
2. Back up file databases with connections closed, or use DuckDB's supported
   export/backup procedure. Test against a disposable copy of production data.
3. Pin both extension **1.2.2** and DuckDB **1.5.6** in vendored/source builds.
   Use matching `duckdb.h` and `libduckdb`; rebuild `duckdb.so` for the deployment
   PHP ABI. Distribution-library packages follow the distribution's engine
   version instead, so record and test that resolved version.
4. Run the extension suite and application queries, including prepared binds,
   type conversion, streaming, transactions, appenders, and the application's
   asynchronous integration. Validate actual loaded versions, then restart
   long-running PHP processes after installing the new binaries.

DuckDB database extensions installed with SQL `INSTALL`/`LOAD` are separate
from the PHP `duckdb` extension. Reinstall compatible engine extensions when
needed; an old downloaded DuckDB extension binary is not automatically
compatible with the new engine. See DuckDB's
[extension versioning guidance](https://duckdb.org/docs/stable/extensions/versioning_of_extensions).

For rollback, stop processes using the database, restore the previous PHP
extension and matching engine library (or image), and restart. Restore the
backup if the database was changed into a state the old engine cannot read.
Do not assume that downgrading a library also reverses database changes; see
DuckDB's [storage compatibility guidance](https://duckdb.org/docs/stable/internals/storage).

## Policy for future releases

This policy governs migration guidance for future extension changes. It does
not certify untested engine/PHP combinations or promise a fixed support period.

| Change | Release and migration approach |
| --- | --- |
| Internal fix preserving documented PHP behavior | Patch release, with focused regression validation |
| Additional PHP methods, types, or optional behavior | Minor release; document availability and examples, preserve existing defaults |
| Incompatible PHP signatures, return shapes, defaults, or removals | Major release, with before/after migration instructions |
| Upstream C API deprecation | Audit internal call sites; preserve PHP behavior while validating a replacement |
| PHP API deprecation | Document replacement and reason in a release before removal; announce the intended removal release |
| New engine dependency | Update pins and compatibility evidence together; declare the required engine version explicitly |

For each engine update, compare the tagged C headers. Separate new
functionality from stabilization, aliases, and documentation changes, then
check all used symbols, ownership rules, callback threading, error handling,
and result semantics. A C API rename need not become a PHP rename.

Implement newly useful capabilities as explicit PHP interfaces with validated
resource lifetimes and examples. Add targeted tests and run the configured PHP
and packaging matrices before recording those combinations as tested. Keep
necessary deprecated C dependencies isolated and documented; remove them only
when the replacement preserves observable behavior. Avoid enabling unstable
C APIs for a released PHP feature without an explicit compatibility decision.

Publish a release migration entry containing the extension/engine versions,
new APIs, changed behavior, deprecations, tested combinations, and rollback
instructions. Update this compatibility document when validation results or
required versions change.
