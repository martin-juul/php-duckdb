# API Reference

This reference lists every class, method, enum and function exported by the
extension. [`duckdb.stub.php`](../duckdb.stub.php) is the canonical source and
also serves as the stub for IDEs and static analysis.

Namespace: `DuckDB`.

## Function

### `DuckDB\version(): string`

Returns the version of the linked DuckDB library, e.g. `"v1.5.6"`. The global
`duckdb_version()` provides the same information for backwards compatibility.

## Enums

### `FetchMode`

Controls the row shape returned by `Result::fetchRow()` / `Result::fetchAll()`.

| Case | Shape |
| ------------------ | ------------------------------------------------------- |
| `FetchMode::Assoc` | `array<string, mixed>` — column name => value (default) |
| `FetchMode::Num` | `list<mixed>` — 0-based positional |
| `FetchMode::Both` | both of the above merged in one array |

### `ErrorType: int`

Machine-readable DuckDB error category, mirroring the C `duckdb_error_type`.
Every `DuckDB\Exception` carries it as `$e->getCode()`; recover it with
`ErrorType::tryFrom($e->getCode())` or `$e->getErrorType()`.

Cases (backing value in parentheses): `Invalid` (0), `OutOfRange` (1),
`Conversion` (2), `UnknownType` (3), `Decimal` (4), `MismatchType` (5),
`DivideByZero` (6), `ObjectSize` (7), `InvalidType` (8), `Serialization` (9),
`Transaction` (10), `NotImplemented` (11), `Expression` (12), `Catalog` (13),
`Parser` (14), `Planner` (15), `Scheduler` (16), `Executor` (17), `Constraint`
(18), `Index` (19), `Stat` (20), `Connection` (21), `Syntax` (22), `Settings`
(23), `Binder` (24), `Network` (25), `Optimizer` (26), `NullPointer` (27), `Io`
(28), `Interrupt` (29), `Fatal` (30), `Internal` (31), `InvalidInput` (32),
`OutOfMemory` (33), `Permission` (34), `ParameterNotResolved` (35),
`ParameterNotAllowed` (36), `Dependency` (37), `Http` (38), `MissingExtension`
(39), `Autoload` (40), `Sequence` (41), `InvalidConfiguration` (42).

## Exceptions

```text
DuckDB\Exception (extends \Exception)
├── ConnectionException   — opening / connecting / closed-connection use
├── ParserException       — SQL syntax errors
├── BinderException       — unknown identifiers, unresolved parameters
├── CatalogException      — missing tables, schemas, dependencies
├── ConstraintException   — PK / UNIQUE / FK / NOT NULL / CHECK violations
├── TransactionException  — conflicts, invalid transaction state
├── ConversionException   — casts, out-of-range, division by zero
├── IOException           — file system, network, HTTP, permissions
├── InterruptedException  — query interrupted (Connection::interrupt(), cancel())
└── InternalException     — internal DuckDB errors
```

`Exception::getErrorType(): ?ErrorType` returns the category when known. See
[errors.md](errors.md).

## `final class Interval implements \JsonSerializable`

An interval stores months, days and microseconds as a DuckDB `INTERVAL` value.
The driver returns it for `INTERVAL` columns and accepts it through
`Statement::bindValue()` and `Appender::appendRow()`.

| Method | Description |
| -------------------------------------------------------------- | ------------------------------------------------------- |
| `__construct(int $months = 0, int $days = 0, int $micros = 0)` | |
| `getMonths(): int` | |
| `getDays(): int` | |
| `getMicros(): int` | |
| `__toString(): string` | DuckDB-style rendering, e.g. `4 months 5 days 00:01:00` |
| `jsonSerialize(): array` | `array{months: int, days: int, micros: int}` |
| `static fromSeconds(float $seconds): Interval` | Create from (fractional) seconds |

## `class Value`

| Method | Behavior |
| ----------------------------------------- | ------------------------------------------------------------------------ |
| `__construct(string $type, mixed $value)` | Validate a SQL type declaration and snapshot input without a connection. |
| `getType(): string` | Canonical declared type. |
| `toString(Connection $connection): string` | DuckDB display text using the connection's catalog and settings; SQL NULL displays as `NULL`. |

See [typed value input and conversion](value.md). Serialization is denied.

### Native typed subclasses

The extension registers final subclasses directly under `DuckDB`. Each inherits
final `getType(): string` and `toString(Connection $connection): string`, and
accepts a typed NULL. No autoloader is required.

| Class | Constructor |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `Boolean` | `__construct(mixed $value)` |
| `TinyInt`, `SmallInt`, `Integer`, `BigInt`, `HugeInt` | `__construct(mixed $value)` |
| `UTinyInt`, `USmallInt`, `UInteger`, `UBigInt`, `UHugeInt` | `__construct(mixed $value)` |
| `BigNum`, `Float32`, `Double` | `__construct(mixed $value)` |
| `Varchar`, `Blob`, `Bit`, `Uuid`, `Json` | `__construct(mixed $value)` |
| `Date`, `Time`, `TimeNs`, `TimeTz` | `__construct(mixed $value)` |
| `TimestampS`, `TimestampMs`, `Timestamp`, `TimestampNs`, `TimestampTz` | `__construct(mixed $value)` |
| `IntervalValue`, `Variant` | `__construct(mixed $value)` |
| `Decimal` | `__construct(mixed $value, int $precision = 18, int $scale = 3)` |
| `Enum` | `__construct(mixed $value, array $labels)` |
| `ListValue` | `__construct(mixed $value, string\|Value $elementType)` |
| `ArrayValue` | `__construct(mixed $value, string\|Value $elementType, int $length)` |
| `Struct` | `__construct(mixed $value, array $fields)` |
| `Map` | `__construct(mixed $value, string\|Value $keyType, string\|Value $valueType)` |
| `Union` | `__construct(mixed $value, ?string $tag, array $members)` |
| `Geometry` | `__construct(mixed $value, ?string $crs = null)` |
| `CatalogValue` | `__construct(mixed $value, string $name, ?string $schema = null, ?string $catalog = null)` |

Type specifications are SQL strings, scalar class names or typed `Value`
instances. See [constructor examples and input shapes](value.md).

## `final class Database`

A DuckDB database instance.

### `__construct(string $path = ':memory:', array $config = [])`

Opens the database with the DuckDB configuration options in `$config`, an
`array<string, string|int|float|bool>`. For example:
`new Database('db.duckdb', ['access_mode' => 'read_only', 'threads' => 4])`.

Open failures throw the exception matching DuckDB's error category, such as
`IOException` for file-lock conflicts. Unclassified open failures throw
`ConnectionException`; unknown options throw `DuckDB\Exception` with
`ErrorType::InvalidConfiguration`. A config value that is not a scalar throws
`\ValueError`.

### `connect(): Connection`

Opens a new connection to this database. One database may have many connections;
each serializes its own statements. Use one connection per thread/fiber of work.
Throws `ConnectionException`.

## `final class Connection`

Created via `Database::connect()`. Not constructible directly.

| Method | Description |
| ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `query(string $sql): Result` | Execute SQL, buffer the full result in memory |
| `queryStreaming(string $sql): Result` | Execute SQL, produce rows chunk-by-chunk (constant memory). `rowCount()` is not meaningful |
| `queryAsync(string $sql): PendingQuery` | Run the query on a background worker thread |
| `queryPending(string $sql): PendingQuery` | Single-threaded async: `isReady()`/`await()` execute the query in slices on the calling thread |
| `execute(string $sql, array $params = []): Result` | Prepare + bind + execute in one call. List keys bind positionally (`?`/`$1`), string keys bind named parameters (`$name`/`:name`) |
| `prepare(string $sql): Statement` | Prepare a statement with positional or named parameters |
| `appender(string $table, ?string $schema = null, ?string $catalog = null): Appender` | Bulk inserter. Throws `CatalogException` when the table does not exist |
| `interrupt(): void` | Interrupt all running queries on this connection (they fail with `InterruptedException`) |
| `getTableNames(string $sql): array` | `list<string>` of tables referenced by the query |
| `close(): void` | Mark the connection closed (idempotent); further use throws `ConnectionException` |
| `isClosed(): bool` | Whether `close()` has been called |
| `queryProgress(): array` | `array{percentage: float, rowsProcessed: int, totalRowsToProcess: int}`; `percentage` is -1 when unavailable. Safe to call from another thread/fiber |
| `beginTransaction(): void` | PDO-style transaction helpers. Throw `TransactionException` on invalid state |
| `commit(): void` | |
| `rollBack(): void` | |

## `final class Statement`

Create a statement through `Connection::prepare()` and reuse it with different
parameters.

| Method | Description |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `bindValue(int\|string $param, mixed $value): Statement` | Fluent bind. `$param` is a 1-based position or a name (with/without `$`/`:` prefix). PHP→DuckDB mapping: `null`→NULL, `bool`→BOOLEAN, `int`→BIGINT, `float`→DOUBLE, `string`→VARCHAR, `Interval`→INTERVAL, `DateTimeInterface`→TIMESTAMP (µs), `list`→LIST, `array<string,mixed>`→STRUCT |
| `bindBlob(int\|string $param, string $data): Statement` | Bind a binary string as BLOB |
| `clearBindings(): void` | Remove all bound values |
| `parameterCount(): int` | |
| `parameterName(int $param): string` | Name at 1-based position (e.g. `$name`, or `1` for `?`) |
| `parameterType(int\|string $param): string` | DuckDB type of the parameter, if resolved |
| `statementType(): string` | e.g. `SELECT`, `INSERT`, `CREATE` |
| `columnCount(): int` | Result columns (0 when no result set) |
| `columnName(int $index): string` | 0-based |
| `columnType(int $index): string` | DuckDB type name of the 0-based column |
| `execute(array $params = []): Result` | Execute, optionally binding `$params` first |
| `executeStreaming(array $params = []): Result` | Execute with a streaming result |
| `executeAsync(array $params = []): PendingQuery` | Execute on a background worker thread |

`bindValue()` throws `\ValueError` for an invalid parameter index or unsupported
value, and `DuckDB\Exception` subclasses for DuckDB-side errors.

## `final class Result implements \IteratorAggregate`

A query result is consumed as you iterate over it.

| Method | Description |
| ------------------------------------------------------ | --------------------------------------------------------------- |
| `columnCount(): int` | |
| `columnName(int $index): string` | 0-based; throws `\ValueError` out of range |
| `columnType(int $index): string` | DuckDB type name; throws `\ValueError` out of range |
| `columns(): array` | `list<array{name: string, type: string}>` |
| `rowCount(): int` | Total rows (not meaningful for streaming results) |
| `rowsChanged(): int` | Rows changed by INSERT/UPDATE/DELETE (0 otherwise) |
| `statementType(): string` | e.g. `SELECT`, `INSERT` |
| `fetchRow(FetchMode $mode = FetchMode::Assoc): ?array` | Next row or `null` when exhausted |
| `fetchAll(FetchMode $mode = FetchMode::Assoc): array` | All remaining rows |
| `fetchColumn(int $column = 0): mixed` | Single column of the next row, `null` when exhausted |
| `getIterator(): \Iterator` | Forward-only `ResultIterator` (`foreach` fetches associatively) |

See [types.md](types.md) for the full DuckDB→PHP value mapping.

## `final class ResultIterator implements \Iterator`

Returned by `Result::getIterator()`. Forward-only: it cannot be rewound once
advanced. Standard `current() / key() / next() / rewind() / valid()`.

## `final class PendingQuery`

A pending query represents asynchronous execution started through
`queryAsync()`, `queryPending()`, or `executeAsync()`. Its result can be consumed
exactly once.

| Method | Description |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `isReady(): bool` | Non-blocking completion check. For `queryPending()` handles this also executes one task slice on the calling thread |
| `await(): Result` | Block until completion; throws on query failure |
| `suspend(): Result` | Suspend the current fiber/coroutine until completion (Swoole 6+, True Async, AMPHP v3, react/async v4+, or generic fibers) |
| `cancel(): void` | Cancel the query; it fails with `InterruptedException` |
| `getFd(): int` | Caller-owned duplicate completion handle; Unix fd or Windows Winsock SOCKET; -1 if unavailable. See [ownership](async.md#event-loops-completion-descriptor) |
| `getStream(): mixed` | Readable PHP stream that fires on completion (`stream_select()`-able). Can be taken only once |

See [async.md](async.md).

## `final class Appender`

Created via `Connection::appender()`. Fast row-by-row bulk inserts.

| Method | Description |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `appendRow(array $values): void` | Append one complete row (`list<mixed>` in column order, same type mapping as `bindValue()`). All values are converted before the row starts, so a PHP-side failure leaves the appender usable |
| `beginRow(): void` | Begin a piecemeal row (throws `\Error` if a row is open) |
| `append(mixed $value): void` | Append one value at the next column of the open row |
| `appendDefault(): void` | Append the column default at the next position |
| `endRow(): void` | Finish the open row |
| `flush(): void` | Flush pending rows to the table |
| `clear(): void` | Discard buffered and partial rows and reset a failed state; already-flushed rows are unchanged |
| `close(): void` | Close permanently (idempotent), flushing pending rows or discarding them if failed; destruction does the same on a best-effort basis |

After a native submission or flush failure, call `clear()` to discard buffered
data before reuse, or `close()` to discard the appender. `clear()` cannot undo
already-flushed rows or reopen a closed appender. See [appender.md](appender.md).
