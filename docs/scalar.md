# Scalar functions in PHP

These APIs are under development in this checkout. They are not included in
the released 1.3.1 archive.

Register a PHP class as a SQL scalar function with a fixed signature:

```php
$conn->registerScalarFunction(
    'invoice_adjust_php',
    ['BIGINT', 'BIGINT'],
    'BIGINT',
    new AdjustInvoice($db->connect()),
);

$rows = $conn->query('SELECT invoice_adjust_php(1250, -100) AS cents')->fetchAll();
```

The runnable [invoice example](../examples/scalar.php) implements the three
interfaces and preserves SQL NULLs. The executing connection and the vector
writer are separate connections to the same database.

## Registration and types

`Connection::registerScalarFunction(string $name, array $parameterTypes,
string|Value $returnType, ScalarFunction $function): void` resolves the
parameter and return types when registering. Type inputs follow
[`createVector()`](vector.md): SQL declarations, scalar value class names,
or typed `DuckDB\Value` objects. Types do not change during bind or init.

A name is unique, case insensitively, in the database catalog. Registering the
same name again, overloading it, or replacing an existing built-in is
rejected, including from another connection to that database. There is no
unregister API: names remain reserved after the registering connection is
closed, until the database itself closes. Registrations retain their PHP
function objects, and only the connection that registered a function may
execute it. Other connections cannot execute it.

Keep that PHP connection alive while prepared or pending statements use its
functions. Releasing it inside a callback retires the registration and fails
the statement after the callback returns safely.

## Bind, init and execute

| Interface | Method | Responsibility |
| --- | --- | --- |
| `ScalarFunction` | `bind(array $argumentTypes, array $constantArguments): ScalarFunctionBinding` | Inspect resolved types and constant inputs; return binding state |
| `ScalarFunctionBinding` | `init(): ScalarFunctionExecutor` | Create execution state for a DuckDB local worker |
| `ScalarFunctionExecutor` | `execute(DataChunk $arguments): Vector` | Compute one output value for each input row |

All interfaces are in the `DuckDB` namespace. `bind()` receives a list of
rendered SQL type names for the argument expressions, before implicit casts
to the registered signature. For example, an integer literal can report
`INTEGER` for a parameter registered as `BIGINT`. Constant arguments are
decoded PHP values keyed by zero-based argument position. Use
`array_key_exists()` to distinguish a
constant SQL NULL, which has a present key with value `null`, from a
nonconstant expression, which has no key. Constant metadata is available
while binding; every execution batch still contains all argument columns.

DuckDB controls the number and timing of bind and init calls. A prepared
statement can retain its binding across executions, while DuckDB creates
executors for local worker state. `init()` is not a once-per-query hook.
Keep per-executor state on the executor, and do not rely on a particular
callback count or on destructors as completion notifications. There is no
`close()` or `abort()` callback contract.

Functions are registered as volatile and use special NULL handling. DuckDB
passes NULL inputs to PHP instead of automatically replacing their results
with NULL. Your executor decides the result for those rows.

## Batches and return vectors

`execute()` receives a borrowed [`DataChunk`](data_chunk.md), valid only
until that call returns. Retaining the chunk does not extend its lifetime;
accessing it later throws `Error`. Its `vector()` and `select()` methods
make owned copies that remain usable afterwards.

Return an owned [`Vector`](vector.md) whose type matches the registered
return type, including nested type details, and whose capacity is at least
`$arguments->rowCount()`. Vectors have capacity rather than a separate logical
length. DuckDB consumes the first input-row-count values. Returning a vector
with the wrong type or insufficient capacity fails the statement.

Create and fill output vectors with a separate connection. Callback code
must not use the connection currently executing the scalar function,
including for vector creation or conversion. Use that separate connection to
resolve any catalog types needed by the output vector.

## Execution restrictions

Bind, init and execute always run on the PHP request thread. DuckDB workers
hand callbacks to that thread while it drives the statement. Callbacks must
return synchronously: suspending a Fiber inside them is rejected. Exceptions
from a callback fail the statement; the connection can be used again after
the failure.

Buffered queries, prepared execution and single-threaded pending queries
support scalar callbacks. A Fiber may drive a pending query with `suspend()`;
that does not permit callbacks themselves to suspend. Worker-backed
`queryAsync()` and `executeAsync()` reject statements requiring PHP scalar
callbacks.

Currently, registering any PHP scalar function makes `queryStreaming()` and
`executeStreaming()` buffer their complete result on that connection, even
for statements that do not call the function. This keeps callbacks inside a
live request-thread execution pump. Those results do not have the usual
constant-memory streaming guarantee. Use a connection without PHP scalar
registrations when true streaming is required.
