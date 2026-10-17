# Migrations

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
