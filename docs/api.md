# API Reference

This reference lists every class, method, enum and function exported by the
extension. [`duckdb.stub.php`](../duckdb.stub.php) is the canonical source and
also serves as the stub for IDEs and static analysis. This reference follows
the current checkout; Arrow and vector APIs are under development and are not
included in the released 1.3.1 archive.

Namespace: `DuckDB`.

## Functions

### `DuckDB\version(): string`

Returns the version of the linked DuckDB library, e.g. `"v1.5.6"`. The global
`duckdb_version()` provides the same information for backwards compatibility.

### `DuckDB\vectorSize(): int`

Returns the number of rows in a standard DuckDB vector, 2048 for the pinned
engine. It is the default vector capacity and the maximum row count of
`DataChunk::fromVectors()` and `DataChunk::select()`.

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
`arrow_lossless_conversion` defaults to `true`; explicitly supplying `false`
is honored. See [Arrow conversion settings](arrow.md#type-conversion-settings)
for extension metadata and rejected lossy representations.

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
| `dataChunkFromArrow(ArrowChunk $chunk): DataChunk` | Convert an Arrow batch to a reusable native chunk, consuming its input |
| `createVector(string\|Value $type, ?int $capacity = null): Vector` | Create an owned, NULL-initialized vector of a type resolved on this connection |
| `interrupt(): void` | Interrupt the currently running query on this connection; does not cancel queued work |
| `getTableNames(string $sql): array` | `list<string>` of tables referenced by the query |
| `registerCopyToFunction(string $name, CopyToFunction $function): void` | Make `$name` a `COPY ... TO` format on this connection, implemented in PHP. See [COPY TO formats](copy.md) |
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
| `executeAsync(array $params = []): PendingQuery` | Execute on a background worker thread; rejects statements that use a PHP COPY format |

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
| `arrowSchema(): ArrowSchema` | Inspect the result schema without consuming rows |
| `fetchArrowChunk(): ?ArrowChunk` | Fetch the next complete batch; rejects a partially read row batch without discarding rows |
| `fetchColumn(int $column = 0): mixed` | Single column of the next row, `null` when exhausted |
| `getIterator(): \Iterator` | Forward-only `ResultIterator` (`foreach` fetches associatively) |

See [types.md](types.md) for the full DuckDB→PHP value mapping.

## `final class ArrowSchema`

An owning C Data Interface schema with a private constructor. Arrow APIs are
under development in this checkout and are not in the released 1.3.1 archive.

| Method | Description |
| --- | --- |
| `importFromC(int $address): ArrowSchema` (static) | Move a live native schema; clear its source release callback |
| `exportToC(int $address): void` | Copy into an empty native schema; caller owns its release |
| `toArray(): array` | Recursive name, format, flags, metadata pairs, children and dictionary |

## `final class ArrowChunk`

An owning Arrow record batch with a private constructor.

| Method | Description |
| --- | --- |
| `importFromC(ArrowSchema $schema, int $address): ArrowChunk` (static) | Move a live native array with its matching struct-root schema |
| `exportToC(int $address): void` | Move into an empty native array; consume this chunk |
| `schema(): ArrowSchema` | Schema, available after consumption |
| `rowCount(): int` | Batch length, available after consumption |
| `isConsumed(): bool` | Whether the array has been moved or converted |

## `final class DataChunk`

A native chunk created by Arrow conversion or from vectors, with a private
constructor. It retains its buffers independently of the source result,
vectors and connection.

| Method | Description |
| --- | --- |
| `static fromVectors(array $vectors, int $rowCount): DataChunk` | Copy the first rows of named vectors into a chunk of at most `vectorSize()` rows |
| `vector(int $index): Vector` | Copy a column into a new vector whose capacity is the row count |
| `select(SelectionVector\|array $selection): DataChunk` | Copy the selected rows of every column into a new chunk of at most `vectorSize()` rows |
| `rowCount(): int` | Number of rows |
| `columnCount(): int` | Number of columns |
| `columns(): array` | `list<array{name: string, type: string}>` |
| `toRows(FetchMode $mode = FetchMode::Assoc): array` | Decode all rows without consuming them |
| `arrowSchema(Connection $connection): ArrowSchema` | Export a schema using the connection's conversion settings |
| `toArrow(Connection $connection): ArrowChunk` | Export a new Arrow batch without consuming this chunk |

A batch passed to `CopyToWriter::write()` is only valid during that call; see
[COPY TO formats](copy.md).

See [Arrow conversion](arrow.md) for ownership, native address requirements,
FFI examples, mixed row fetching and type conversion semantics. Result and
DataChunk export reject lossy representations of HUGEINT, UHUGEINT, BIT and
TIMETZ, including nested values; enable `arrow_lossless_conversion` on the
exporting connection. The bundled SDK also includes engine fixes for Arrow
conversion transactions and geometry CRS preservation.

## `final class Vector`

An owned, fixed-capacity native vector, created by `Connection::createVector()`,
`DataChunk::vector()` or `Vector::select()`. Every row of a new vector is NULL.
Vectors remain usable after their connection and database close.

| Method | Description |
| --- | --- |
| `type(): string` | Rendered type, as `DataChunk::columns()` reports it |
| `capacity(): int` | Number of rows |
| `get(int $index): mixed` | Decode one row with the result type mappings |
| `isNull(int $index): bool` | Whether one row is NULL |
| `toArray(int $offset = 0, ?int $length = null): array` | Decode a range of rows; by default, through the capacity |
| `set(Connection $connection, int $index, mixed $value): void` | Convert a value as typed binding does and write it |
| `setValues(Connection $connection, array $values, int $offset = 0): void` | Write a list to consecutive rows; a rejected value leaves the vector unchanged |
| `setNull(int $index): void` | Mark one row NULL |
| `copyFrom(Vector $source, int $sourceOffset = 0, ?int $count = null, int $targetOffset = 0): void` | Copy rows from a vector of the same type |
| `select(SelectionVector\|array $selection): Vector` | Copy the selected rows into a new vector |
| `copySelected(Vector $source, SelectionVector\|array $selection, int $targetOffset = 0): void` | Write source row `$selection[i]` to row `$targetOffset + i`; the source must have the same type |

Indices outside the capacity throw `ValueError`. Converting input other than
plain scalars of the vector's type executes on the supplied connection and
invalidates its streaming result. See [vectors](vector.md) for conversion,
ownership and chunk semantics.

## `final class SelectionVector implements \Countable`

An immutable list of source row indices with a public constructor. It needs no
connection and remains usable after every connection and database closes.
Methods that take a selection also accept a plain list of row indices.

| Method | Description |
| --- | --- |
| `__construct(array $indices)` | Store a list of integers from 0 to 4294967294; indices may repeat and appear in any order |
| `count(): int` | Number of indices |
| `get(int $position): int` | Index at a 0-based position |
| `toArray(): array` | All indices, in order |

Non-integer elements throw `TypeError`; non-list arrays and out-of-range
indices throw `ValueError`. An index at or beyond the source row count throws
`ValueError` when the selection is used. See
[selection vectors](selection.md).

## `interface CopyToFunction`

A `COPY ... TO` format, registered with `Connection::registerCopyToFunction()`.
Its methods run on the request thread and must not suspend.

| Method | Description |
| --- | --- |
| `bind(array $columnTypes, array $options): void` | Validate a statement that uses the format; throw to reject it with a `BinderException` |
| `open(string $path, array $columnTypes, array $options): CopyToWriter` | Start one execution writing `$path` |

`$columnTypes` lists SQL type declarations. `$options` maps upper-cased format
option names to values, sorted by name.

## `interface CopyToWriter`

Receives the batches of one `COPY ... TO` execution.

| Method | Description |
| --- | --- |
| `write(DataChunk $batch): void` | Receive one batch, valid only during the call; columns are named `col0` … `colN-1` |
| `close(): void` | The COPY succeeded |
| `abort(\Throwable $reason): void` | The COPY failed after the writer was opened |

See [COPY TO formats](copy.md) for call order, threading, paths and errors.

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
| `cancel(): void` | Idempotently cancel this pending query, including queued/startup worker work; observe `InterruptedException` when awaiting completion. Other queries are unaffected |
| `getFd(): int` | Caller-owned duplicate completion handle; Unix fd or Windows Winsock SOCKET; -1 if unavailable. See [ownership](async.md#event-loops-completion-descriptor) |
| `getStream(): mixed` | Readable PHP stream that fires on completion (`stream_select()`-able). Can be taken only once |

See [async.md](async.md).

## `final class Appender`

Created via `Connection::appender()`. Fast row-by-row bulk inserts.

| Method | Description |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `appendRow(array $values): void` | Append one complete row (`list<mixed>` in column order, same type mapping as `bindValue()`). All values are converted before the row starts, so a PHP-side failure leaves the appender usable |
| `appendChunk(DataChunk $chunk): void` | Append a reusable native chunk; no piecemeal row may be open |
| `appendArrow(ArrowChunk $chunk): void` | Convert and append an Arrow batch, consuming it |
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
