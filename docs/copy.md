# COPY TO formats

These APIs are under development in this checkout. They are not included in
the released 1.3.1 archive.

`Connection::registerCopyToFunction()` adds a `COPY ... TO` output format
implemented in PHP. DuckDB runs the query and streams every result batch to
the PHP handler, which writes the file:

```php
$conn->registerCopyToFunction('markdown', new MarkdownTable());

$conn->query("COPY (SELECT region, sum(amount) FROM sales GROUP BY ALL)
    TO 'summary.md' (FORMAT markdown, COLUMNS ['Region', 'Total'])");
```

A format implements two interfaces:

| Interface | Method | Called |
| --- | --- | --- |
| `DuckDB\CopyToFunction` | `bind(array $columnTypes, array $options): void` | When a statement using the format is prepared or bound again |
| `DuckDB\CopyToFunction` | `open(string $path, array $columnTypes, array $options): CopyToWriter` | Once per execution, before the first batch |
| `DuckDB\CopyToWriter` | `write(DataChunk $batch): void` | For every batch, in order |
| `DuckDB\CopyToWriter` | `close(): void` | Once, after the last batch, when the COPY succeeds |
| `DuckDB\CopyToWriter` | `abort(\Throwable $reason): void` | Once, instead of `close()`, when the COPY fails after `open()` |

See the [runnable example](../examples/copy_to.php) for a complete format.

## Register a format

```php
$conn->registerCopyToFunction(string $name, DuckDB\CopyToFunction $function): void
```

The name selects the format in `FORMAT <name>`. A target whose file extension
equals the name selects it as well, so `TO 'report.markdown'` uses a format
named `markdown`. Names are case-insensitive identifiers of letters, digits
and underscores. Registering a name again on the same connection replaces its
handler; statements prepared before the change fail and must be prepared
again.

Registration throws `ValueError` for an invalid name or the name of a built-in
format such as `csv`, `parquet` or `json`. It throws `DuckDB\Exception` when
DuckDB or a loaded extension already provides a format of that name, when the
connection is closed, while a COPY runs on the connection, or inside a
handler.

A format belongs to the connection that registered it. Other connections,
including other connections to the same database, do not see it: there,
`FORMAT <name>` fails with DuckDB's missing-format `CatalogException` and a
matching file extension falls back to CSV. The registration ends when the
`Connection` object is freed. A COPY that is already running completes with
its handler; statements prepared on the connection then fail.

## Handler contract

`bind()` validates a statement. `$columnTypes` lists the SQL type of each
column, such as `INTEGER`, `DECIMAL(18,3)` or `STRUCT("k" INTEGER)`.
`$options` holds the format options of the statement, keyed by upper-cased
name and sorted by key:

| Option | `$options` entry |
| --- | --- |
| `quality 7` | `'QUALITY' => 7` |
| `header` (no value) | `'HEADER' => null` |
| `columns ['a', 'b']` | `'COLUMNS' => ['a', 'b']` |
| `mixed (1, 'x')` | `'MIXED' => [1, 'x']` |

Options that DuckDB handles itself, such as `FORMAT`, `USE_TMP_FILE` and
`WRITE_EMPTY_FILE`, are not passed. Values decode like query results. Throwing
from `bind()` rejects the statement with a `DuckDB\BinderException`. DuckDB may
bind a prepared statement more than once, for example again when it executes
with parameters, so `bind()` should have no side effects.

`open()` receives the same types and options and returns the writer for one
execution. A prepared statement executed three times opens three writers.
`open()` runs before the first batch. When the query returns no rows, it runs
at completion, so an empty file is still written; with `WRITE_EMPTY_FILE
false`, no handler method runs at all.

`write()` receives each batch as a `DataChunk` of at most `DuckDB\vectorSize()`
rows. COPY functions do not receive column names, so the columns are named
`col0`, `col1` and so on; pass names as an option when the format needs them.
The batch is only valid during the call. Using it afterwards throws `Error`;
keep data with `toRows()`, or with `select()` or `vector()`, which return
copies.

Exactly one of `close()` and `abort()` runs for every opened writer, with the
two exceptions under [errors](#errors). `abort()` receives the handler's
exception when one caused the failure, and otherwise a `DuckDB\Exception`
describing it.

## Target paths

`$path` is the file DuckDB expects the writer to create. A relative target is
made absolute against the process working directory, which DuckDB uses too.
Remote URLs are passed unchanged.

The writer must create that file, even when it writes nothing to it. When the
target already exists, DuckDB's default `USE_TMP_FILE` behavior passes a
`tmp_`-prefixed path and renames it over the target after `close()` succeeds;
the statement fails with an `IOException` if the file is missing. When the
COPY fails, DuckDB deletes the file it expected, so a writer does not need to
remove partial output in `abort()`.

## Threading

DuckDB executes COPY callbacks on its worker threads, but PHP code may only
run on the request thread. On a connection with a registered format, every
statement runs through a pump: the request thread drives DuckDB and runs the
handler calls that workers hand to it, between execution slices. Handler code
therefore always runs on the request thread, in the order DuckDB requests it.

This works for:

- `Connection::query()`, including multi-statement SQL, `execute()` and
  `queryStreaming()`;
- `Statement::execute()` and `executeStreaming()`;
- `Connection::queryPending()`, with `PendingQuery::isReady()`, `await()` and
  `suspend()`, including the Swoole, True Async, AMPHP and ReactPHP
  integrations.

`queryAsync()` and `Statement::executeAsync()` run statements on a worker
thread that cannot reach PHP. A statement that uses a PHP format fails there
with a `DuckDB\Exception` naming those methods; `executeAsync()` rejects it
before starting.

Handlers must not yield. `Fiber::suspend()` and runtime suspension inside a
handler throw `FiberError`, which fails the COPY. Inside a handler:

- using the connection that runs the COPY throws a `ConnectionException`,
  including through objects that belong to it;
- other connections work normally;
- running another PHP-format COPY, on any connection, is rejected;
- freeing a `Result` or `PendingQuery` of the busy connection is deferred
  until the handler returns.

Statements on connections without a registered format are unaffected.

## Unsupported options

PHP formats write one file from one writer at a time. With the bundled DuckDB
engine, `PARTITION_BY`, `PER_THREAD_OUTPUT` and `EXPORT DATABASE` are rejected
with a `DuckDB\BinderException` when the statement is bound. An external
library without the bundled patch rejects them only when a second output
starts, with a `DuckDB\Exception`. See [compatibility](compatibility.md).
DuckDB itself rejects `FILE_SIZE_BYTES` for formats that cannot rotate files.

## Errors

A handler failure fails the statement:

| Failure | Statement error |
| --- | --- |
| `bind()` throws | `DuckDB\BinderException` |
| `open()`, `write()` or `close()` throws | `DuckDB\Exception` naming the format and method |
| An engine error, interrupt or cancellation | The usual DuckDB exception |

The handler's exception is chained as `getPrevious()`. The connection stays
usable afterwards.

`exit()` inside a handler aborts the COPY, runs `abort()`, and then exits
normally once DuckDB has unwound. A fatal error, including a memory or time
limit, ends the request as usual; neither `close()` nor `abort()` runs, and
the writer is released without calling PHP. The same applies to writers that
are still open once the request is past its destructor phase.

Discarding an unfinished `PendingQuery` or cancelling it aborts its writers
with a `DuckDB\Exception`. So does any other use of its connection before the
query finishes, including binding values, appending rows or freeing an
`Appender`: DuckDB cancels the unfinished statement when the connection is used
again, and the failure says that the COPY statement was superseded.

## FrankenPHP and long-running workers

A registration lives in its `Connection` object, so registering on a
persistent per-worker connection keeps the format for the worker's lifetime.
Formats are never shared between worker threads or requests. See
[FrankenPHP](frankenphp.md).
