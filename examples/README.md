# Runnable examples

These scripts show application workflows and focused API usage. Run them
against a built extension through the harness:

```sh
php tests/harness.php examples
```

The harness executes every `examples/*.php` with the extension loaded.
Optional runtime examples exit with a skip message when their runtime is
absent; that does not demonstrate execution of their integration branch.
`bootstrap.php` checks the extension and is shared setup, rather than an
application example. See the [API reference](../docs/api.md) and
[canonical signatures](../duckdb.stub.php).

## Current scripts

| Example | Purpose | Requirements |
| --- | --- | --- |
| [sync_basic.php](sync_basic.php) | Buffered queries, column metadata, row fetching and the legacy version alias | Extension |
| [api_reference.php](api_reference.php) | Document storage, statement/result metadata, all fetch modes, explicit iterators and appender recovery | Extension |
| [order_analytics.php](order_analytics.php) | Local CSV order ingestion, exact money, transactional validation, finance reporting and streaming export | Extension |
| [prepared.php](prepared.php) | Parameter binding, prepared worker execution and single-threaded pending queries | Extension |
| [transactions.php](transactions.php) | Exact-cent transfers, funds/account checks, rejected transfer rollback and transaction errors | Extension |
| [persistent_reports.php](persistent_reports.php) | File-backed summary storage, database release/reopen, read-only reporting and temporary-file cleanup | Extension |
| [appender.php](appender.php) | Sensor ingestion with whole rows, piecemeal rows and defaults | Extension |
| [typed_values.php](typed_values.php) | Complete typed input dictionary, exact numeric/temporal values, Interval APIs and invoice ingestion | Extension |
| [typed_adapter.php](typed_adapter.php) | A DBAL-style adapter contract for money and LIST parameters | Extension; no Doctrine dependency |
| [value_rendering.php](value_rendering.php) | Explicit display text, catalog types, timezone settings and binary strings | Extension |
| [arrow.php](arrow.php) | Streaming Arrow batches copied between connections | Extension; no FFI required |
| [arrow_c_data.php](arrow_c_data.php) | External buffer access and schema/array ownership across the C Data Interface | Enabled PHP FFI |
| [errors.php](errors.php) | Typed errors and machine-readable error categories | Extension |
| [async_jobs.php](async_jobs.php) | Reporting deadlines, progress, cancellation, polling and a minimal Fiber scheduler | Extension |
| [async_concurrent.php](async_concurrent.php) | Concurrent background queries with `stream_select()` | Extension |
| [amphp.php](amphp.php) | Concurrent analytical queries through AMPHP fibers | AMPHP v3/Revolt |
| [reactphp.php](reactphp.php) | ReactPHP concurrency and cancellation | react/async v4+ |
| [swoole.php](swoole.php) | Concurrent analytical queries through Swoole coroutines | Swoole 6+ |
| [true_async.php](true_async.php) | True Async concurrency and cancellation | True Async PHP runtime |
| [frankenphp.php](frankenphp.php) | Persistent per-worker analytical state | FrankenPHP worker mode and [Caddyfile](frankenphp.Caddyfile) |

## Public API audit

The stub currently declares 62 classes and 129 public methods, plus
`DuckDB\version()` and the compatibility alias `duckdb_version()`. It also
exposes the three `FetchMode` cases and 43 `ErrorType` cases. The inventory
below maps each explicitly declared public method to an executable example.
Comments and `Class::class` type specifications do not count as constructor
demonstrations. Inherited standard PHP exception methods need no duplicate
example for each subclass. This is an API map, not a runtime coverage report:
optional FFI and framework paths run only when their requirements are met.

| Class or group | Public methods or cases | Example |
| --- | --- | --- |
| `Database` | `__construct`, `connect` | [sync_basic.php](sync_basic.php), [async_jobs.php](async_jobs.php) (configuration), [persistent_reports.php](persistent_reports.php) (file reopen/read-only) |
| `Connection`: synchronous queries | `query`, `queryStreaming`, `execute`, `prepare`, `appender` | [prepared.php](prepared.php), [order_analytics.php](order_analytics.php) |
| `Connection`: transactions | `beginTransaction`, `commit`, `rollBack` | [transactions.php](transactions.php), [order_analytics.php](order_analytics.php) |
| `Connection`: metadata and lifecycle | `getTableNames`, `close`, `isClosed` | [api_reference.php](api_reference.php) |
| `Connection`: background queries | `queryAsync`, `queryPending`, `interrupt`, `queryProgress` | [async_jobs.php](async_jobs.php) |
| `Connection`: Arrow ingestion | `dataChunkFromArrow` | [arrow.php](arrow.php) |
| `Statement`: binding and execution | `bindValue`, `bindBlob`, `clearBindings`, `execute`, `executeStreaming` | [api_reference.php](api_reference.php) |
| `Statement`: introspection | `parameterCount`, `parameterName`, `parameterType`, `statementType`, `columnCount`, `columnName`, `columnType` | [api_reference.php](api_reference.php) |
| `Statement`: background execution | `executeAsync` | [async_jobs.php](async_jobs.php) |
| `Result`: introspection | `columnCount`, `columnName`, `columnType`, `columns`, `rowCount`, `rowsChanged`, `statementType` | [api_reference.php](api_reference.php) |
| `Result`: PHP rows | `fetchRow`, `fetchAll`, `fetchColumn`, `getIterator` | [api_reference.php](api_reference.php) |
| `Result`: Arrow batches | `arrowSchema`, `fetchArrowChunk` | [arrow.php](arrow.php) |
| `ResultIterator` | `current`, `key`, `next`, `rewind`, `valid` | [api_reference.php](api_reference.php) (explicit calls) |
| `PendingQuery` | `isReady`, `await`, `getStream`, `cancel`, `getFd`, `suspend` | [async_jobs.php](async_jobs.php); `getFd` demonstrates polling mode's `-1`, native worker descriptor ownership is explained there |
| `Appender`: rows | `appendRow`, `beginRow`, `append`, `appendDefault`, `endRow`, `close` | [appender.php](appender.php) |
| `Appender`: recovery | `flush`, `clear` | [api_reference.php](api_reference.php) |
| `Appender`: batches | `appendChunk`, `appendArrow` | [arrow.php](arrow.php) |
| `ArrowSchema` | `toArray`, `importFromC`, `exportToC` | [arrow.php](arrow.php), [arrow_c_data.php](arrow_c_data.php) |
| `ArrowChunk` | `importFromC`, `exportToC`, `schema`, `rowCount`, `isConsumed` | [arrow_c_data.php](arrow_c_data.php), [arrow.php](arrow.php) |
| `DataChunk` | `rowCount`, `columnCount`, `columns`, `toRows`, `arrowSchema`, `toArrow` | [arrow.php](arrow.php) |
| `Value` and all 39 subclasses | Every public `__construct`; inherited `getType`, `toString` | [typed_values.php](typed_values.php), dictionary below |
| `Interval` | `__construct`, `getMonths`, `getDays`, `getMicros`, `__toString` through a string cast, `jsonSerialize`, `fromSeconds` | [typed_values.php](typed_values.php) |
| `Exception` | `getErrorType` | [errors.php](errors.php), [async_jobs.php](async_jobs.php) |
| Functions | `DuckDB\version`, `duckdb_version` | [api_reference.php](api_reference.php), [sync_basic.php](sync_basic.php) |
| `FetchMode` | `Assoc`, `Num`, `Both` | [api_reference.php](api_reference.php), [arrow.php](arrow.php) |
| `ErrorType` | All cases via `cases`, backed lookup via `from` and `tryFrom` | [errors.php](errors.php) |

[errors.php](errors.php) catches the base `Exception` and real
`CatalogException`, `ConstraintException`, `ParserException`,
`BinderException`, `ConversionException`, `IOException` and
`ConnectionException` failures. [transactions.php](transactions.php) shows
`TransactionException`; [async_jobs.php](async_jobs.php) handles
`InterruptedException`. `InternalException` indicates an engine defect;
intentionally provoking one is not a runnable application pattern.

## Typed constructor dictionary

[typed_values.php](typed_values.php) constructs and binds `Value` plus every
one of the 39 native subclasses below. Its loop executes each value through a
prepared statement and checks the native declaration and display text. SQL
string casts retain nanosecond precision for the reference output; normal PHP
result mappings still apply to application queries.

| Family | Explicit constructors |
| --- | --- |
| Boolean and signed integers | `Boolean`, `TinyInt`, `SmallInt`, `Integer`, `BigInt`, `HugeInt` |
| Unsigned and arbitrary precision integers | `UTinyInt`, `USmallInt`, `UInteger`, `UBigInt`, `UHugeInt`, `BigNum` |
| Floating point and decimal | `Float32`, `Double`, `Decimal` |
| Text, binary and structured scalar values | `Varchar`, `Blob`, `Bit`, `Uuid`, `Json`, `Variant` |
| Temporal values | `Date`, `Time`, `TimeNs`, `TimeTz`, `TimestampS`, `TimestampMs`, `Timestamp`, `TimestampNs`, `TimestampTz`, `IntervalValue` |
| Containers and declared labels | `Enum`, `ListValue`, `ArrayValue`, `Struct`, `Map`, `Union`, `CatalogValue` |
| Geometry | `Geometry`, including a CRS declaration |

## Choosing a workflow

Start with [order_analytics.php](order_analytics.php) for a local extract-to-report
workflow: validate CSV fields, ingest in a transaction, bind exact decimal and
date values, aggregate a finance report and stream a CSV export. Use
[arrow.php](arrow.php) for a batch pipeline that preserves nested and decimal
values without rebuilding every row in PHP. [async_jobs.php](async_jobs.php)
adds deadlines and cancellation to background analytical jobs.

[api_reference.php](api_reference.php) and
[typed_values.php](typed_values.php) are focused references for generic client
implementations and explicit input declarations. In-memory examples need no
external service or network access. The typed dictionary documents a native
prepared-query limitation and its query rewrite in
[prepared statements](../docs/prepared.md#native-query-shape-limitation).
