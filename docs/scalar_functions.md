# PHP scalar functions

These APIs are under development after extension 1.4.1.

`Connection::registerScalarFunction()` adds a SQL scalar function implemented
in PHP. DuckDB supplies native input batches; PHP returns one value for each
row. Binding and worker initialization have separate objects so that prepared
SQL and each executing worker can retain their own state.

```php
$conn->registerScalarFunction(
    'normalize_label',
    ['VARCHAR'],
    'VARCHAR',
    new NormalizeLabel(),
);
$conn->query("SELECT normalize_label(label) FROM labels");
```

See the [runnable example](../examples/scalar_functions.php) for the complete
`NormalizeLabel` implementation, prepared reuse and pending execution.

## Register a function

```php
$conn->registerScalarFunction(
    string $name,
    array $parameterTypes,
    string|DuckDB\Value $returnType,
    DuckDB\ScalarFunction $function,
): void
```

`$parameterTypes` is a list in SQL argument order. Type declarations follow
[typed values](value.md): SQL strings such as `BIGINT` or `DECIMAL(18,2)`,
scalar wrapper class names such as `DuckDB\Integer::class`, or `Value`
instances. An empty list declares a function with no arguments. The return
type uses the same declaration rules. Function calls have the declared arity;
DuckDB resolves casts of SQL arguments to the registered parameter types.

A name must be nonempty and contain no NUL byte. Names are case-insensitive
for ASCII letters. Registering the name again replaces its handler for future
statements. Replacements must keep the same parameter and return types.
Statements prepared against the previous registration must be prepared again.
Built-in function names cannot be replaced.

The function belongs to its registering connection. Other connections,
including connections to the same database, cannot invoke its PHP handler.
The registration ends when that connection is freed. Do not persist SQL that
requires a request's PHP handler for use by a different connection or request.

## Bind, initialize, execute

| Interface | Method | When called |
| --- | --- | --- |
| `ScalarFunction` | `bind(array $argumentTypes): ScalarBinding` | While DuckDB binds one use of the function |
| `ScalarBinding` | `init(): ScalarExecutor` | For each DuckDB worker that begins executing that bound function |
| `ScalarExecutor` | `execute(DataChunk $arguments): array` | For each input batch processed by that worker |

`bind()` receives the resolved SQL argument types, in order. It validates the
call and returns a PHP object holding bound state. DuckDB may bind prepared
SQL repeatedly, including after parameters are supplied. Binding should not
have external side effects or assume exactly one call per preparation.

`init()` returns the state for one executing DuckDB worker. A parallel query
may initialize several executors for the same binding. Executor state must
not be used as a global row counter or depend on which worker receives a
batch. `init()` does not promise exactly one call per SQL execution.

`execute()` decodes input with `DataChunk::toRows(DuckDB\FetchMode::Num)`, or
reads owned column copies with `DataChunk::vector()`. Column positions follow
SQL argument order. The input chunk is valid only during the callback. Retain
PHP rows or a copy returned by `vector()` or `select()` when needed later.

The returned array must be a list with exactly `rowCount()` entries, in input
row order. Values are converted to the registered return type using the
[typed input conversion](value.md) rules. Use typed wrappers to preserve
exact decimal, binary or nested values. The resulting SQL type is always the
registered type. NULL is valid for any registered output type. Wrong row
counts, array keys or values that cannot be converted fail the statement.

Output conversion uses a private connection to the same database because the
executing connection is busy inside the callback. Its session settings use
their defaults and can differ from settings on the registering connection.
Catalog types must be visible on the private connection; types created in an
uncommitted transaction may be unavailable there.

## NULLs and volatility

Functions use DuckDB's special NULL handling: rows with NULL inputs are passed
to PHP. The executor chooses whether to return NULL, substitute a default, or
compute another value. DuckDB does not automatically propagate input NULLs.

Functions are volatile. DuckDB does not treat them as deterministic constants
that it can fold during query planning. SQL plans still choose which rows
reach an expression and in what order; a scalar function is unsuitable for
ordered side effects, and callback counts are not a SQL ordering guarantee.

## Threading and execution

Every PHP callback runs on the request thread. DuckDB's workers hand callback
requests to that thread through the query pump. This supports buffered,
streaming and prepared statements and `queryPending()` with polling or
`await()`. A pending handle must be driven for its PHP callbacks to progress.

`queryAsync()` and `Statement::executeAsync()` execute on a background thread
that cannot call PHP, so statements using a PHP scalar function fail there.
Unrelated worker queries remain available.

Callbacks must not suspend. Switching Fibers or yielding through a runtime
inside a callback fails the statement. Using the active connection, or a
statement/appender that belongs to it, is rejected to prevent reentry. Other
connections can perform ordinary SQL; nested PHP callback execution is
rejected. Finish the callback promptly so that cancellation can progress.

## Errors and lifetime

Throw from `bind()`, `init()` or `execute()` to reject the statement. Native
execution is unwound before the failure reaches the caller, and the original
PHP exception is retained as the cause of the DuckDB exception. A subsequent
ordinary query on the connection can run after the failed statement has been
cleaned up.

Cancellation, abandoned pending handles, connection closure and request
shutdown release worker state without running PHP on DuckDB worker threads.
Objects retained by a registration, binding or executor must not assume a
particular destructor order. Use explicit application methods for work that
must complete before shutdown.
