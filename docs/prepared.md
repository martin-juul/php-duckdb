# Prepared Statements

Prepare a statement once, then execute it with different parameter values. The
PHP interface wraps `duckdb_prepare()`, `duckdb_bind_*()` and
`duckdb_execute_prepared()`.

## Preparing and executing

```php
$stmt = $conn->prepare('INSERT INTO users (id, name) VALUES (?, ?)');

$stmt->execute([1, 'Alice']);   // bind + execute in one call
$stmt->execute([2, 'Bob']);     // reuse with new values
```

To bind values explicitly, chain the binding calls before execution:

```php
$stmt->bindValue(1, 3)->bindValue(2, 'Carol');
$result = $stmt->execute();
```

Prepared statements reuse a prepared query across executions. They also pass
values safely: parameters are bound separately and are never
string-concatenated into SQL.

## Parameter styles

| Style      | SQL                | Bind with                                                                             |
| ---------- | ------------------ | ------------------------------------------------------------------------------------- |
| Positional | `?`                | 1-based positions: `execute([$a, $b])` or `bindValue(1, $a)`                          |
| Numbered   | `$1`, `$2`         | same as positional                                                                    |
| Named      | `$name` or `:name` | `execute(['name' => $v])` or `bindValue('name', $v)` (the `$`/`:` prefix is optional) |

Rules for `execute($params)` / `executeStreaming($params)` /
`executeAsync($params)`:

- A **list** binds positionally, in order; the count must match.
- An array with **string keys** binds named parameters.
- Mixed integer keys bind as 1-based positions.

## Binding types

| PHP value                                      | DuckDB type                                                                   |
| ---------------------------------------------- | ----------------------------------------------------------------------------- |
| `DuckDB\Value` and its native typed subclasses | Explicit SQL type; conversion at execution ([classes and contract](value.md)) |
| `null`                                         | NULL                                                                          |
| `bool`                                         | BOOLEAN                                                                       |
| `int`                                          | BIGINT                                                                        |
| `float`                                        | DOUBLE                                                                        |
| `string`                                       | VARCHAR                                                                       |
| `string` via `bindBlob()`                      | BLOB (binary-safe)                                                            |
| `DuckDB\Interval`                              | INTERVAL                                                                      |
| `DateTimeInterface`                            | TIMESTAMP (microsecond precision)                                             |
| `list<mixed>`                                  | LIST                                                                          |
| `array<string, mixed>`                         | STRUCT                                                                        |

```php
$stmt->bindValue(1, new DateTimeImmutable('2026-01-01 12:00:00 UTC'));
$stmt->bindValue(2, Interval::fromSeconds(90.5));
$stmt->bindValue(3, ['a' => 1, 'b' => 'x']);   // STRUCT(a BIGINT, b VARCHAR)
$stmt->bindBlob(4, $binaryPayload);
```

Use native typed inputs to make the schema explicit without writing SQL type
grammar:

```php
$stmt->bindValue('amount', new DuckDB\Decimal('12.345', precision: 18, scale: 2));
$stmt->bindValue('ids', new DuckDB\ListValue([], DuckDB\Integer::class));
```

Invalid parameter indexes or unsupported values throw `\ValueError`. When
DuckDB rejects a binding, the driver throws a typed exception, usually
`BinderException` / `ConversionException`.

## Introspection

```php
$stmt->parameterCount();     // 2
$stmt->parameterName(1);     // '$name' for named, '1' for positional
$stmt->parameterType(1);     // 'BIGINT' (when DuckDB has resolved it)
$stmt->statementType();      // 'SELECT', 'INSERT', ...
$stmt->columnCount();        // result columns (0 for statements without a result set)
$stmt->columnName(0);
$stmt->columnType(0);
$stmt->clearBindings();      // unbind everything
```

## Streaming and async execution

```php
$result = $stmt->executeStreaming([$id]);   // constant-memory result, see query.md
$pending = $stmt->executeAsync([$id]);      // background thread, see async.md
```

## Not exposed

- `duckdb_extract_statements()` (splitting a multi-statement string into
  individual prepared statements) has no PHP wrapper. Issue statements one at a
  time, or send the whole script to `Connection::query()`, which executes
  multiple statements.

Typed native value binding is exposed through [`DuckDB\Value`](value.md).
