# Querying

Execute SQL through a connection, then read the returned result. The PHP
interface wraps `duckdb_query()`, `duckdb_fetch_chunk()` and the
`duckdb_result` inspection functions.

## Buffered queries

```php
$result = $conn->query('SELECT * FROM users WHERE active = true');

$rows = $result->fetchAll();                 // list<array<string, mixed>>
$row  = $result->fetchRow();                 // next row or null
$name = $result->fetchColumn();              // first column of the next row
```

`query()` buffers the entire result set in memory before returning. Prefer it
for small/medium results and DDL/DML.

For statements with parameters, `execute()` prepares the statement, binds its
values, and executes it in one call (see [prepared.md](prepared.md)):

```php
$result = $conn->execute('SELECT * FROM users WHERE id = ?', [$id]);
$result = $conn->execute('SELECT * FROM users WHERE id = $id', ['id' => $id]);
```

## Fetch modes

```php
use DuckDB\FetchMode;

$result->fetchRow(FetchMode::Assoc);  // ['id' => 1, 'name' => 'Alice']  (default)
$result->fetchRow(FetchMode::Num);    // [1, 'Alice']
$result->fetchRow(FetchMode::Both);   // both merged in one array
```

## Iteration

`Result` implements `IteratorAggregate`. Each step of `foreach` fetches an
associative row and consumes it, moving forward through the result:

```php
foreach ($conn->query('SELECT * FROM users') as $row) {
    echo $row['name'];
}
```

## Metadata

```php
$result->columnCount();     // 3
$result->columnName(0);     // 'id'
$result->columnType(0);     // 'BIGINT'
$result->columns();         // [['name' => 'id', 'type' => 'BIGINT'], ...]
$result->rowCount();        // total rows (buffered results)
$result->rowsChanged();     // rows affected by INSERT/UPDATE/DELETE
$result->statementType();   // 'SELECT', 'INSERT', ...
```

## Streaming queries (constant memory)

```php
$result = $conn->queryStreaming('SELECT * FROM huge_table');
while ($row = $result->fetchRow()) {
    // process one row at a time; only one chunk is held in memory
}
```

The streaming path produces rows chunk by chunk as you iterate. It corresponds
to the C API's `duckdb_fetch_chunk()` path and holds only a chunk at a time, so
arbitrarily large results stream through in constant memory.

Streaming caveats:

- `rowCount()` is **not meaningful** for streaming results (it reports only
  what has materialized so far).
- A streaming result is bound to its connection: **starting a new execution
  on the same connection invalidates the in-flight stream**, which then
  throws on the next fetch. Keep one connection per active stream (or finish
  consuming before reusing the connection).
- Errors that occur mid-stream (e.g. a conversion error in row 10 million)
  surface as an exception from `fetchRow()`/`fetchAll()` mid-iteration.

## Errors

When DuckDB reports a failure, the driver throws a subclass of
`DuckDB\Exception`. The exception carries the category (`ErrorType`) as its
code; see [errors.md](errors.md):

```php
use DuckDB\{Exception, ErrorType};

try {
    $conn->query('SELECT * FROM missing_table');
} catch (Exception $e) {
    $e->getErrorType();   // ErrorType::Catalog
}
```
