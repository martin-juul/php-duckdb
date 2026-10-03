# Error Handling

When an operation fails, catch its exception class or inspect its error
category to decide how to handle it.

## Exception hierarchy

Every error that originates in DuckDB throws a subclass of `DuckDB\Exception`
(itself a `\Exception`):

```text
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

PHP-side misuse throws PHP core errors instead of driver exceptions:
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

`getErrorType()` returns `?ErrorType` by calling
`ErrorType::tryFrom($e->getCode())`. It returns `null` when no category is known.

Notable categories (full list in [api.md](api.md#errortype-int)): `Parser`,
`Binder`, `Catalog`, `Constraint`, `Transaction`, `Conversion`, `Io`, `Http`,
`Interrupt`, `OutOfMemory`, `Permission`, `InvalidConfiguration`,
`MissingExtension`, `InvalidInput`.

## Where errors can come from

| Operation | Typical exception |
| --- | --- |
| `new Database($path, $config)` | `DuckDB\Exception` with `ErrorType::InvalidConfiguration` for unknown options; `IOException` with `ErrorType::Io` for lock/permission failures; `ConnectionException` for unclassified open failures |
| `query()` / `execute()` / `prepare()` | `ParserException`, `BinderException`, `CatalogException`, … |
| `fetchRow()` mid-stream | the query's deferred error (e.g. `ConversionException`) |
| `PendingQuery::await()` / `suspend()` | rethrows the background query's failure on the awaiting fiber/thread |
| `Appender` methods | native submission or flush failures require `clear()` before reuse; conversion failures before submission leave it usable |

## Interrupts and cancellation

A query interrupted through `Connection::interrupt()` or
`PendingQuery::cancel()` fails with `InterruptedException`
(`ErrorType::Interrupt`). When implementing timeouts, distinguish this
cancellation from other failures:

```php
use DuckDB\InterruptedException;

try {
    $pending->await();
} catch (InterruptedException) {
    // we cancelled this ourselves
}
```
