<?php

/**
 * duckdb - native DuckDB driver for PHP.
 *
 * This stub file is the single source of truth for every class, method and
 * function signature exported by the extension. `duckdb_arginfo.h` is
 * generated from it with php-src's stub generator:
 *
 *     php /path/to/php-src/build/gen_stub.php duckdb.stub.php
 *
 * The generated header is committed to the repository so that end users
 * building via phpize/PECL do not need a php-src checkout. Never edit
 * `duckdb_arginfo.h` by hand; regenerate it after changing this file.
 *
 * The stub doubles as an IDE / static-analysis stub (Psalm, PHPStan).
 *
 * @generate-class-entries
 */

namespace DuckDB;

/**
 * Row shape returned by {@see Result::fetchRow()} / {@see Result::fetchAll()}.
 *
 * - `Assoc`: column name => value (duplicate column names overwrite earlier ones)
 * - `Num`:   0-based positional array
 * - `Both`:  both of the above in one array
 */
enum FetchMode
{
    case Assoc;
    case Num;
    case Both;
}

/**
 * Machine-readable DuckDB error category.
 *
 * Mirrors DuckDB's `duckdb_error_type` C enum. Every {@see Exception} carries
 * the category as its code (`$e->getCode()`), so it can be recovered with
 * `ErrorType::tryFrom($e->getCode())`.
 */
enum ErrorType: int
{
    case Invalid = 0;
    case OutOfRange = 1;
    case Conversion = 2;
    case UnknownType = 3;
    case Decimal = 4;
    case MismatchType = 5;
    case DivideByZero = 6;
    case ObjectSize = 7;
    case InvalidType = 8;
    case Serialization = 9;
    case Transaction = 10;
    case NotImplemented = 11;
    case Expression = 12;
    case Catalog = 13;
    case Parser = 14;
    case Planner = 15;
    case Scheduler = 16;
    case Executor = 17;
    case Constraint = 18;
    case Index = 19;
    case Stat = 20;
    case Connection = 21;
    case Syntax = 22;
    case Settings = 23;
    case Binder = 24;
    case Network = 25;
    case Optimizer = 26;
    case NullPointer = 27;
    case Io = 28;
    case Interrupt = 29;
    case Fatal = 30;
    case Internal = 31;
    case InvalidInput = 32;
    case OutOfMemory = 33;
    case Permission = 34;
    case ParameterNotResolved = 35;
    case ParameterNotAllowed = 36;
    case Dependency = 37;
    case Http = 38;
    case MissingExtension = 39;
    case Autoload = 40;
    case Sequence = 41;
    case InvalidConfiguration = 42;
}

/**
 * Base class for every error raised by the driver.
 *
 * `getCode()` returns an {@see ErrorType} backing value when the error
 * originated inside DuckDB, or 0 ({@see ErrorType::Invalid}) otherwise.
 * Specific failure modes throw the dedicated subclasses listed below.
 */
class Exception extends \Exception
{
    /** The DuckDB error category, when known. */
    public function getErrorType(): ?ErrorType {}
}

/** Errors while opening or connecting to a database. */
class ConnectionException extends Exception {}

/** SQL parser / syntax errors. */
class ParserException extends Exception {}

/** Binder errors: unknown identifiers, unresolved parameters, type resolution. */
class BinderException extends Exception {}

/** Catalog errors: missing tables, schemas, dependencies. */
class CatalogException extends Exception {}

/** Constraint violations (PRIMARY KEY, UNIQUE, FOREIGN KEY, NOT NULL, CHECK). */
class ConstraintException extends Exception {}

/** Transaction errors (conflicts, invalid transaction state, sequences). */
class TransactionException extends Exception {}

/** Value conversion errors (casts, out-of-range, division by zero). */
class ConversionException extends Exception {}

/** I/O errors: file system, network, HTTP, permissions, extension loading. */
class IOException extends Exception {}

/** The query was interrupted (see {@see Connection::interrupt()}). */
class InterruptedException extends Exception {}

/** Internal DuckDB errors. Please report these upstream. */
class InternalException extends Exception {}

/**
 * A DuckDB `INTERVAL` value: months, days and microseconds.
 *
 * Used both ways: returned for `INTERVAL` columns and accepted by
 * {@see Statement::bindValue()} and {@see Appender::appendRow()}.
 */
final class Interval implements \JsonSerializable
{
    public function __construct(int $months = 0, int $days = 0, int $micros = 0) {}

    public function getMonths(): int {}

    public function getDays(): int {}

    public function getMicros(): int {}

    /** DuckDB-style rendering, e.g. `4 months 5 days 00:01:00`. */
    public function __toString(): string {}

    /** @return array{months: int, days: int, micros: int} */
    public function jsonSerialize(): array {}

    /** Create an interval from a number of seconds (may be fractional). */
    public static function fromSeconds(float $seconds): Interval {}
}

/**
 * An immutable, connection-independent input with an explicit SQL type.
 *
 * Syntax and PHP input are validated and snapshotted at construction.
 * Catalog resolution and DuckDB casts happen on the consuming connection.
 * Serialization is not supported.
 */
class Value
{
    public function __construct(string $type, mixed $value) {}

    /** Return the canonical declared SQL type. */
    final public function getType(): string {}

    /** Render display text using this connection's type resolution and settings. */
    final public function toString(Connection $connection): string {}
}

/** Native typed input classes; every class accepts a typed NULL. */
final class Boolean extends Value
{
    public function __construct(mixed $value) {}
}

final class TinyInt extends Value
{
    public function __construct(mixed $value) {}
}

final class SmallInt extends Value
{
    public function __construct(mixed $value) {}
}

final class Integer extends Value
{
    public function __construct(mixed $value) {}
}

final class BigInt extends Value
{
    public function __construct(mixed $value) {}
}

final class UTinyInt extends Value
{
    public function __construct(mixed $value) {}
}

final class USmallInt extends Value
{
    public function __construct(mixed $value) {}
}

final class UInteger extends Value
{
    public function __construct(mixed $value) {}
}

final class UBigInt extends Value
{
    public function __construct(mixed $value) {}
}

final class HugeInt extends Value
{
    public function __construct(mixed $value) {}
}

final class UHugeInt extends Value
{
    public function __construct(mixed $value) {}
}

final class BigNum extends Value
{
    public function __construct(mixed $value) {}
}

final class Float32 extends Value
{
    public function __construct(mixed $value) {}
}

final class Double extends Value
{
    public function __construct(mixed $value) {}
}

final class Varchar extends Value
{
    public function __construct(mixed $value) {}
}

final class Blob extends Value
{
    public function __construct(mixed $value) {}
}

final class Bit extends Value
{
    public function __construct(mixed $value) {}
}

final class Uuid extends Value
{
    public function __construct(mixed $value) {}
}

final class Json extends Value
{
    public function __construct(mixed $value) {}
}

final class Date extends Value
{
    public function __construct(mixed $value) {}
}

final class Time extends Value
{
    public function __construct(mixed $value) {}
}

final class TimeNs extends Value
{
    public function __construct(mixed $value) {}
}

final class TimeTz extends Value
{
    public function __construct(mixed $value) {}
}

final class TimestampS extends Value
{
    public function __construct(mixed $value) {}
}

final class TimestampMs extends Value
{
    public function __construct(mixed $value) {}
}

final class Timestamp extends Value
{
    public function __construct(mixed $value) {}
}

final class TimestampNs extends Value
{
    public function __construct(mixed $value) {}
}

final class TimestampTz extends Value
{
    public function __construct(mixed $value) {}
}

final class IntervalValue extends Value
{
    public function __construct(mixed $value) {}
}

final class Variant extends Value
{
    public function __construct(mixed $value) {}
}

final class Decimal extends Value
{
    public function __construct(mixed $value, int $precision = 18, int $scale = 3) {}
}

final class Enum extends Value
{
    public function __construct(mixed $value, array $labels) {}
}

final class ListValue extends Value
{
    public function __construct(mixed $value, string|Value $elementType) {}
}

final class ArrayValue extends Value
{
    public function __construct(mixed $value, string|Value $elementType, int $length) {}
}

final class Struct extends Value
{
    /** @param array $fields Field name => SQL string, scalar class name or Value. */
    public function __construct(mixed $value, array $fields) {}
}

final class Map extends Value
{
    public function __construct(mixed $value, string|Value $keyType, string|Value $valueType) {}
}

final class Union extends Value
{
    /** @param array $members Member name => SQL string, scalar class name or Value. */
    public function __construct(mixed $value, ?string $tag, array $members) {}
}

final class Geometry extends Value
{
    public function __construct(mixed $value, ?string $crs = null) {}
}

final class CatalogValue extends Value
{
    public function __construct(mixed $value, string $name, ?string $schema = null, ?string $catalog = null) {}
}

/**
 * A DuckDB database instance. Open with a file path or `:memory:`.
 *
 * The second constructor argument accepts any DuckDB configuration option,
 * e.g. `new Database('db.duckdb', ['access_mode' => 'read_only',
 * 'threads' => 4, 'memory_limit' => '1GB'])`.
 *
 * @see https://duckdb.org/docs/stable/configuration/overview
 */
final class Database
{
    /**
     * @param string $path Database file path, or `:memory:` (the default).
     * @param array $config DuckDB configuration options, as
     *        `array<string, string|int|float|bool>`: option name => value,
     *        e.g. `['access_mode' => 'read_only', 'threads' => 4]`.
     *
     * @throws ConnectionException When the database cannot be opened.
     * @throws \ValueError When a configuration value is not a scalar.
     */
    public function __construct(string $path = ':memory:', array $config = []) {}

    /**
     * Open a new connection to this database.
     *
     * A single database may have many connections; each connection serializes
     * its own statements. Use one connection per thread/fiber of work.
     *
     * @throws ConnectionException
     */
    public function connect(): Connection {}
}

/**
 * A connection to a DuckDB database. Created via {@see Database::connect()}.
 *
 * Connections are not safe for concurrent use from multiple threads, so the
 * driver serializes statements issued on the same connection internally.
 */
final class Connection
{
    /** Convert an Arrow record batch using this connection's settings; consumes its array. */
    public function dataChunkFromArrow(ArrowChunk $chunk): DataChunk {}

    private function __construct() {}

    /**
     * Execute a SQL statement and buffer the full result in memory.
     *
     * @throws Exception On any DuckDB error (see the Exception subclasses).
     */
    public function query(string $sql): Result {}

    /**
     * Execute a SQL statement and return a streaming result.
     *
     * Rows are produced chunk by chunk while iterating, so arbitrarily large
     * results can be processed in constant memory. {@see Result::rowCount()}
     * is not meaningful for streaming results.
     *
     * @throws Exception
     */
    public function queryStreaming(string $sql): Result {}

    /**
     * Start the query on a background worker thread.
     *
     * The returned handle integrates with event loops: poll
     * {@see PendingQuery::isReady()}, wait on {@see PendingQuery::getStream()}
     * / {@see PendingQuery::getFd()}, or suspend a fiber with
     * {@see PendingQuery::suspend()}.
     */
    public function queryAsync(string $sql): PendingQuery {}

    /**
     * Single-threaded async: the query is executed in small slices by
     * {@see PendingQuery::isReady()} / {@see PendingQuery::await()} on the
     * calling thread. No worker threads are spawned, which is useful inside
     * `dl()`-restricted environments or custom event loops.
     */
    public function queryPending(string $sql): PendingQuery {}

    /**
     * Convenience wrapper: prepare `$sql`, bind `$params`, execute.
     *
     * A list binds positionally (`?` / `$1` parameters, in order); an array
     * with string keys binds named parameters (`$name` / `:name`).
     */
    public function execute(string $sql, array $params = []): Result {}

    /**
     * Prepare a SQL statement with positional (`?`, `$1`) or named
     * (`$name`, `:name`) parameters.
     *
     * @throws Exception When the statement cannot be prepared.
     */
    public function prepare(string $sql): Statement {}

    /**
     * Create an appender for fast bulk inserts into `$table`.
     *
     * @throws CatalogException When the table does not exist.
     */
    public function appender(string $table, ?string $schema = null, ?string $catalog = null): Appender {}

    /**
     * Interrupt all currently running queries on this connection.
     *
     * Affected queries fail with {@see InterruptedException}.
     */
    public function interrupt(): void {}

    /**
     * Names of the tables referenced by the given (qualified) query.
     *
     * @return list<string>
     */
    public function getTableNames(string $sql): array {}

    /**
     * Close the connection.
     *
     * Marks the connection closed: every further use throws
     * {@see ConnectionException}. Idempotent. The underlying DuckDB
     * connection is destroyed when the object (and every object derived
     * from it) is freed.
     */
    public function close(): void {}
    /** Whether {@see close()} has been called. */
    public function isClosed(): bool {}

    /**
     * Progress of the query currently running on this connection.
     *
     * Safe to call from another thread/fiber while a query runs.
     *
     * @return array{percentage: float, rowsProcessed: int, totalRowsToProcess: int}
     *         `percentage` is -1 when no progress information is available.
     */
    public function queryProgress(): array {}

    /**
     * Start a transaction (PDO-style convenience wrapper).
     *
     * @throws TransactionException When a transaction is already active.
     */
    public function beginTransaction(): void {}

    /**
     * Commit the active transaction.
     *
     * @throws TransactionException When no transaction is active.
     */
    public function commit(): void {}

    /**
     * Roll back the active transaction.
     *
     * @throws TransactionException When no transaction is active.
     */
    public function rollBack(): void {}
}

/**
 * A prepared statement. Created via {@see Connection::prepare()}.
 *
 * Statements can be executed repeatedly with different parameter values.
 */
final class Statement
{
    private function __construct() {}

    /**
     * Bind a value to a parameter (fluent).
     *
     * `$param` is a 1-based position for positional parameters or the
     * parameter name (with or without `$`/`:` prefix) for named parameters.
     *
     * PHP type mapping:
     *  - `null`                -> NULL
     *  - `bool`                -> BOOLEAN
     *  - `int`                 -> BIGINT
     *  - `float`               -> DOUBLE
     *  - `string`              -> VARCHAR (see {@see Statement::bindBlob()} for BLOB)
     *  - {@see Interval}       -> INTERVAL
     *  - `DateTimeInterface`   -> TIMESTAMP (microsecond precision)
     *  - `list<mixed>`         -> LIST
     *  - `array<string,mixed>` -> STRUCT
     *
     * @throws \ValueError On an invalid parameter index or unsupported value.
     * @throws Exception On a DuckDB binding error.
     */
    public function bindValue(int|string $param, mixed $value): Statement {}

    /**
     * Bind a PHP string as a DuckDB `BLOB` (binary-safe).
     *
     * @throws Exception
     */
    public function bindBlob(int|string $param, string $data): Statement {}

    /** Remove all bound parameter values. */
    public function clearBindings(): void {}

    /** Number of parameters in the prepared statement. */
    public function parameterCount(): int {}

    /**
     * Name of the parameter at the given 1-based position
     * (e.g. `$name` for named parameters, `1` for `?`).
     */
    public function parameterName(int $param): string {}

    /** DuckDB type of the given parameter (e.g. `BIGINT`), if resolved. */
    public function parameterType(int|string $param): string {}

    /** DuckDB statement type, e.g. `SELECT`, `INSERT`, `CREATE`. */
    public function statementType(): string {}

    /** Number of result columns (0 for statements without a result set). */
    public function columnCount(): int {}

    /** Name of the 0-based result column. */
    public function columnName(int $index): string {}

    /** DuckDB type name of the 0-based result column. */
    public function columnType(int $index): string {}

    /**
     * Execute the prepared statement, optionally binding `$params` first.
     *
     * @param array $params `array<int|string, mixed>`; see {@see Connection::execute()}.
     * @throws Exception
     */
    public function execute(array $params = []): Result {}

    /**
     * Execute and return a streaming result (constant memory).
     *
     * @param array $params `array<int|string, mixed>`
     * @throws Exception
     */
    public function executeStreaming(array $params = []): Result {}

    /**
     * Execute on a background worker thread.
     *
     * @param array $params `array<int|string, mixed>`
     */
    public function executeAsync(array $params = []): PendingQuery {}
}

/** An owning Arrow C Data Interface schema. Native addresses must refer to valid ABI structs. */
final class ArrowSchema
{
    private function __construct() {}

    /** Move a live ArrowSchema from native memory; clears the source release callback. */
    public static function importFromC(int $address): ArrowSchema {}

    /** Copy into an empty native ArrowSchema. The caller must invoke its release callback. */
    public function exportToC(int $address): void {}

    /** Recursive schema with name, format, flags, metadata pairs, children and dictionary. */
    public function toArray(): array {}
}

/** An owning Arrow record batch. Import, native conversion and export use move semantics. */
final class ArrowChunk
{
    private function __construct() {}

    /** Move a live record-batch ArrowArray from native memory. */
    public static function importFromC(ArrowSchema $schema, int $address): ArrowChunk {}

    /** Move into an empty native ArrowArray; consumes this chunk. */
    public function exportToC(int $address): void {}

    public function schema(): ArrowSchema {}
    public function rowCount(): int {}
    public function isConsumed(): bool {}
}

/** A native DuckDB chunk imported from Arrow. Independent of its source connection. */
final class DataChunk
{
    private function __construct() {}

    public function rowCount(): int {}
    public function columnCount(): int {}

    /** @return list<array{name: string, type: string}> */
    public function columns(): array {}

    /** Decode all rows without consuming the chunk. */
    public function toRows(FetchMode $mode = FetchMode::Assoc): array {}

    public function arrowSchema(Connection $connection): ArrowSchema {}
    public function toArrow(Connection $connection): ArrowChunk {}
}

/**
 * The result of a query. Iterates row by row.
 *
 * Rows are associative arrays by default; pass a {@see FetchMode} to the
 * fetch methods for positional or combined rows. `Result` is
 * {@see IteratorAggregate}, so `foreach ($result as $row)` fetches
 * associatively. Iterating consumes the result.
 *
 * Type mapping (DuckDB -> PHP):
 *  - BOOLEAN                          -> bool
 *  - (U)TINYINT/(U)SMALLINT/(U)INTEGER -> int
 *  - BIGINT/UBIGINT/HUGEINT/UHUGEINT   -> int when it fits, else exact decimal string
 *  - BIGNUM                           -> string (exact decimal)
 *  - FLOAT/DOUBLE                     -> float
 *  - DECIMAL                          -> string (exact decimal, no precision loss)
 *  - VARCHAR/ENUM                     -> string
 *  - BLOB/GEOMETRY                    -> string (binary)
 *  - BIT                              -> string (text of 0 and 1 digits)
 *  - UUID                             -> string (canonical form)
 *  - DATE                             -> \DateTimeImmutable (midnight UTC)
 *  - TIMESTAMP[_S/_MS/_NS/_TZ]        -> \DateTimeImmutable (UTC)
 *  - TIME[_TZ]/TIME_NS                -> string
 *  - INTERVAL                         -> {@see Interval}
 *  - LIST/ARRAY                       -> list<mixed>
 *  - STRUCT                           -> array<string, mixed>
 *  - MAP                              -> array<mixed, mixed> (assoc; list of
 *                                       `['key' => k, 'value' => v]` pairs for
 *                                       non-scalar keys)
 *  - UNION                            -> the member value
 *  - VARIANT                          -> string (JSON rendering)
 *  - NULL                             -> null
 *
 * Non-finite temporal values (`infinity`) are returned as strings.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class Result implements \IteratorAggregate
{
    private function __construct() {}

    /** Export the result schema without consuming rows. */
    public function arrowSchema(): ArrowSchema {}

    /** Fetch one Arrow record batch; rejects a partially consumed row chunk. */
    public function fetchArrowChunk(): ?ArrowChunk {}

    public function columnCount(): int {}

    /**
     * Name of the 0-based column.
     *
     * @throws \ValueError When the index is out of range.
     */
    public function columnName(int $index): string {}

    /**
     * DuckDB type name of the 0-based column (e.g. `BIGINT`, `DECIMAL`).
     *
     * @throws \ValueError When the index is out of range.
     */
    public function columnType(int $index): string {}

    /**
     * Column metadata: a list of `['name' => string, 'type' => string]`.
     *
     * @return list<array{name: string, type: string}>
     */
    public function columns(): array {}

    /**
     * Total number of rows in the result. For streaming results this is the
     * number of rows materialized so far and is generally not meaningful.
     */
    public function rowCount(): int {}

    /**
     * Number of rows changed by an INSERT/UPDATE/DELETE statement
     * (0 for other statements).
     */
    public function rowsChanged(): int {}

    /** DuckDB statement type, e.g. `SELECT`, `INSERT`. */
    public function statementType(): string {}

    /**
     * Fetch the next row, or `null` when the result is exhausted.
     *
     * @return array<int|string, mixed>|null
     */
    public function fetchRow(FetchMode $mode = FetchMode::Assoc): ?array {}

    /**
     * Fetch all remaining rows.
     *
     * @return list<array<int|string, mixed>>
     */
    public function fetchAll(FetchMode $mode = FetchMode::Assoc): array {}

    /**
     * Fetch a single column of the next row, or `null` when exhausted.
     */
    public function fetchColumn(int $column = 0): mixed {}

    /** @return Iterator<int, array<string, mixed>> */
    public function getIterator(): \Iterator {}
}

/**
 * Row iterator returned by {@see Result::getIterator()}.
 *
 * Iterating consumes the underlying result; an iterator cannot be rewound
 * once it has advanced.
 *
 * @implements Iterator<int, array<string, mixed>>
 */
final class ResultIterator implements \Iterator
{
    private function __construct() {}

    /** @return array<string, mixed>|null */
    public function current(): mixed {}

    public function key(): int {}

    public function next(): void {}

    public function rewind(): void {}

    public function valid(): bool {}
}

/**
 * Handle to an asynchronously running query.
 *
 * Returned by {@see Connection::queryAsync()},
 * {@see Connection::queryPending()} and {@see Statement::executeAsync()}.
 * The result can be consumed exactly once, via {@see await()} or
 * {@see suspend()}.
 */
final class PendingQuery
{
    private function __construct() {}

    /**
     * Non-blocking completion check.
     *
     * For handles created with {@see Connection::queryPending()} this also
     * executes one slice of the DuckDB task graph on the calling thread, so
     * polling drives the query forward (it is not a busy-wait).
     */
    public function isReady(): bool {}

    /**
     * Block the current thread until the query completes and return its
     * result.
     *
     * @throws Exception When the query failed.
     */
    public function await(): Result {}

    /**
     * Suspend the current fiber until the query completes (for Fiber-based
     * schedulers; throws when called outside a fiber).
     *
     * When ext-swoole 6+ is loaded and suspend() is called inside a Swoole
     * coroutine, the coroutine is yielded on the query's completion
     * descriptor instead: Swoole's scheduler resumes it when the query
     * finishes, keeping the event loop responsive. No configuration is
     * needed and OpenSwoole is not affected.
     *
     * Under the True Async php-src fork (https://true-async.github.io/),
     * suspend() inside an Async\ coroutine parks the coroutine in the libuv
     * reactor via the async-aware stream_select() (or Async\delay() in
     * polling mode). Coroutine cancellation is cooperative: it interrupts
     * the in-flight query and a \Cancellation escapes this call.
     *
     * When the Revolt event loop is loadable (AMPHP v3), suspend()
     * suspends the current fiber on the loop until the completion stream
     * fires (or yields between task slices in polling mode), keeping the
     * loop responsive. This also works at the top level of a script, where
     * the main context waits by running the loop.
     *
     * With react/async v4+ (ReactPHP), suspend() awaits a promise on the
     * React\EventLoop that resolves on completion-stream readability.
     * Cancelling the surrounding async() promise interrupts the in-flight
     * query and a \RuntimeException escapes this call. react/async v3 is
     * deliberately not engaged.
     *
     * @throws Exception When the query failed.
     */
    public function suspend(): Result {}

    /**
     * Cancel the query. The query fails with {@see InterruptedException}.
     */
    public function cancel(): void {}

    /**
     * Duplicate completion handle owned by the caller; -1 if unavailable.
     * Unix: file descriptor, close with close(). Windows: Winsock SOCKET,
     * close with closesocket(), not _close(). Prefer getStream() in PHP.
     */
    public function getFd(): int {}

    /**
     * A readable PHP stream that becomes readable on completion
     * (`stream_select()` and friends). Can only be taken once; -1 fds
     * (polling mode) are not supported.
     *
     * @return resource
     */
    public function getStream(): mixed {}
}

/**
 * Fast row-by-row bulk inserter. Created via {@see Connection::appender()}.
 *
 * Rows are built either in one call with {@see appendRow()}, or piecemeal:
 * {@see beginRow()}, then one {@see append()}/{@see appendDefault()} per
 * column, then {@see endRow()}. Values are flushed to storage in batches;
 * call {@see flush()} to force a flush, or {@see close()} (also run on
 * destruction) to flush and finish. After native submission or flush fails,
 * call clear() to discard pending data before reuse. Closing or destroying a
 * failed appender discards pending data without flushing it.
 */
final class Appender
{
    private function __construct() {}

    /**
     * Append one complete row. Values follow the same PHP type mapping as
     * {@see Statement::bindValue()}.
     *
     * All values are converted before the row is started, so a PHP-side
     * conversion failure leaves the appender usable.
     *
     * @param array $values `list<mixed>`, in column order.
     * @throws \ValueError When the value count does not match the table.
     * @throws Exception On a conversion or native submission error. Native
     * submission failures require clear() before reuse.
     */
    public function appendRow(array $values): void {}

    /** Append a reusable native chunk in column order. */
    public function appendChunk(DataChunk $chunk): void {}

    /** Convert and consume an Arrow batch, then append it in column order. */
    public function appendArrow(ArrowChunk $chunk): void {}

    /**
     * Begin a new row for piecemeal appending.
     *
     * @throws \Error When a row is already open.
     */
    public function beginRow(): void {}

    /**
     * Append one value to the currently open row, at the next column
     * position. Follows the same PHP type mapping as
     * {@see Statement::bindValue()}.
     *
     * @throws \Error When no row is open.
     * @throws Exception On a conversion or native submission error. Native
     * submission failures require clear() before reuse.
     */
    public function append(mixed $value): void {}

    /**
     * Append the column default value at the next position of the
     * currently open row.
     *
     * @throws \Error When no row is open.
     */
    public function appendDefault(): void {}

    /**
     * Finish the currently open row.
     *
     * @throws \Error When no row is open.
     */
    public function endRow(): void {}

    /** Flush pending rows to the table. */
    public function flush(): void {}

    /**
     * Discard all buffered rows and any partial row, clearing a failed state.
     * Already-flushed data is unchanged. Closed appenders cannot be cleared.
     */
    public function clear(): void {}

    /** Close permanently (idempotent), flushing pending rows or discarding them if failed. */
    public function close(): void {}
}

/**
 * Version of the linked DuckDB library, e.g. `"v1.5.6"`.
 *
 * Also available as the global `duckdb_version()` for backwards
 * compatibility with pre-1.0 releases of this extension.
 */
function version(): string {}
