# Error Handling

How DuckDB errors surface in PHP, and how to catch them precisely.

## Exception hierarchy

Every error that originates in DuckDB throws a subclass of `DuckDB\Exception`
(itself a `\Exception`):

```
DuckDB\Exception
├── ConnectionException    opening/connecting, using a closed connection
├── ParserException        SQL syntax errors
├── BinderException        unknown identifiers, unresolved parameters, type resolution
├── CatalogException       missing tables/schemas, dependency errors
├── ConstraintException    PRIMARY KEY / UNIQUE / FOREIGN KEY / NOT NULL / CHECK
├── TransactionException   transaction conflicts and invalid transaction state
├── ConversionException    failed casts, out-of-range values, division by zero
├── IOException            file system, network, HTTP, permissions, extension loading
├── InterruptedException   query interrupted (Connection::interrupt(), PendingQuery::cancel())
└── InternalException      internal DuckDB errors (please report upstream)
```

PHP-side misuse throws SPL exceptions instead of driver exceptions:
`\ValueError` (bad parameter index, unsupported bind value, non-scalar config
value, NUL bytes in strings), `\TypeError` (wrong argument types), `\Error`
(appender row-state mistakes).

## Machine-readable categories: `ErrorType`

Every driver exception carries DuckDB's error category as its **code**,
mirroring the C `duckdb_error_type` enum:

```php
use DuckDB\{Exception, ErrorType, CatalogException, ConstraintException};

try {
    $conn->execute('INSERT INTO users (id) VALUES (?)', [$id]);
} catch (ConstraintException $e) {
    // duplicate key, FK violation, … — safe to surface to the user
} catch (Exception $e) {
    match ($e->getErrorType()) {
        ErrorType::Catalog     => log_missing_object($e),
        ErrorType::Transaction => retry($e),
        default                => throw $e,
    };
}
```

`getErrorType()` returns `?ErrorType` — `ErrorType::tryFrom($e->getCode())`
under the hood, `null` when no category is known.

Notable categories (full list in [api.md](api.md#errortype-int)): `Parser`,
`Binder`, `Catalog`, `Constraint`, `Transaction`, `Conversion`, `Io`, `Http`,
`Interrupt`, `OutOfMemory`, `Permission`, `InvalidConfiguration`,
`MissingExtension`, `InvalidInput`.

## Where errors can come from

| Operation | Typical exception |
|---|---|
| `new Database($path, $config)` | `ConnectionException` (`ErrorType::InvalidConfiguration` for unknown options, `ErrorType::Io` for lock/permission failures) |
| `query()` / `execute()` / `prepare()` | `ParserException`, `BinderException`, `CatalogException`, … |
| `fetchRow()` mid-stream | the query's deferred error (e.g. `ConversionException`) |
| `PendingQuery::await()` / `suspend()` | rethrows the background query's failure on the awaiting fiber/thread |
| `Appender` methods | engine failures invalidate the appender (discard it) |

## Interrupts and cancellation

A query killed via `Connection::interrupt()` or `PendingQuery::cancel()`
fails with `InterruptedException` (`ErrorType::Interrupt`) — distinguish it
from real failures when implementing timeouts:

```php
use DuckDB\InterruptedException;

try {
    $pending->await();
} catch (InterruptedException) {
    // we cancelled this ourselves
}
```
