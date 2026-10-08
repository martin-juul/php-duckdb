# Asynchronous Queries

DuckDB runs in-process and has no async wire protocol. Background queries
execute off the calling fiber/thread and notify it on completion. Polling
queries instead execute in small slices on the calling thread. The driver
also integrates with event loops.

## The two modes

```php
// 1. Background thread: DuckDB executes on a worker thread (default, fastest)
$pending = $conn->queryAsync('SELECT * FROM big_table');
$pending = $conn->prepare('SELECT * FROM t WHERE id = ?')->executeAsync([$id]);

// 2. Polling mode: no extension worker thread; isReady()/await() execute the query
//    in small slices on the calling thread (good for dl()-restricted or
//    single-threaded event-loop environments)
$pending = $conn->queryPending('SELECT * FROM big_table');
```

Both return a `DuckDB\PendingQuery`. The result is consumable **exactly once**.

Statements that use a [PHP COPY format](copy.md) need the request thread to run
their handlers, so they work only in polling mode. `queryAsync()` and
`executeAsync()` reject them. A polling query that runs a PHP format calls its
handlers from `isReady()`, `await()` and `suspend()`; the handlers themselves
must not suspend.

## Consuming the result

### Blocking

```php
$result = $pending->await();   // blocks the current thread; throws on failure
```

### Polling

```php
while (!$pending->isReady()) {
    do_other_work();           // in polling mode, isReady() also drives the query
}
$result = $pending->await();
```

### Event loops: completion descriptor

When a background query finishes, it writes a byte to its completion channel.
An event loop can watch that channel for readability. Polling queries have no
completion descriptor.

```php
$fd = $pending->getFd();          // int, for uv_poll() etc.; -1 in polling mode
$stream = $pending->getStream();  // PHP stream resource for stream_select(); once only
```

`getFd()` duplicates the endpoint, so the caller owns and must close the
returned handle. On Unix, that handle is a file descriptor. On Windows, it is
a Winsock `SOCKET` and must be closed by native code with `closesocket()`, not
`_close()`. Windows uses a loopback TCP connection for notification.

Prefer `getStream()` in PHP. It transfers ownership to a stream that works
with `stream_select()` on both platforms; `fclose()` or normal resource
destruction releases it. Closing the stream or a duplicate does not cancel
the query.

### Fibers: `suspend()`

```php
$fiber = new Fiber(function () use ($pending) {
    $result = $pending->suspend();   // parks the fiber until the query completes
    return $result->fetchAll();
});
$fiber->start();
```

`suspend()` auto-detects the scheduler it runs under, in this order:

| Environment | Behavior |
| --- | --- |
| **Swoole 6+** (inside a coroutine) | Yields the coroutine on the completion fd via Swoole's scheduler |
| **True Async** php-src fork | Parks in the libuv reactor; coroutine cancellation interrupts the query and `\Cancellation` escapes |
| **AMPHP v3** (Revolt loop) | Suspends the fiber on the loop; works even at script top level |
| **ReactPHP** (`react/async` v4+) | Awaits a promise on the React loop; cancelling the surrounding `async()` interrupts the query (`\RuntimeException` escapes). v3 is deliberately not engaged |
| **Generic fibers** | `Fiber::suspend()` loop; resume from your own scheduler via `getStream()` readability |

The loaded classes/extensions determine scheduler detection at runtime; no
configuration is needed.

## Cancelling

```php
$pending->cancel();   // idempotent; await() reports InterruptedException
```

Worker cancellation is nonblocking and belongs to that pending query. It
persists through native query startup; queued cancelled work is skipped. It
does not interrupt an earlier or later query on the same connection. Drain the
handle with `await()` or the active scheduler to observe `InterruptedException`
before reusing application state. Cancelling a completed query leaves its
result and connection unchanged. Polling cancellation drains its native pending
work on the calling thread.

## Progress and interruption from elsewhere

```php
$conn->queryProgress();   // ['percentage' => 42.0, 'rowsProcessed' => …, 'totalRowsToProcess' => …]
$conn->interrupt();       // interrupt the currently running connection query
```

`Connection::interrupt()` targets the query currently executing on the
connection; it cannot cancel queued work or reserve an interruption for a
query that has not started. Use `PendingQuery::cancel()` to cancel a specific
background job, including one still queued.

A watchdog fiber can use these methods to display progress or enforce a
timeout. Both are safe to call from another thread/fiber.

## Fan-out example

Use separate connections to the same database so the queries can run in
parallel:

```php
$userConnection = $db->connect();
$orderConnection = $db->connect();
$pendings = [
    'users'  => $userConnection->queryAsync('SELECT * FROM users'),
    'orders' => $orderConnection->queryAsync('SELECT * FROM orders'),
];

$results = [];
foreach ($pendings as $name => $p) {
    $results[$name] = $p->await()->fetchAll();   // both queries were started before waiting
}
```

Statements on one connection serialize. For maximum parallelism, give each
async query its **own connection** (`$db->connect()` is cheap).

Runnable end-to-end scripts live in [`examples/`](../examples):
`async_concurrent.php`, `swoole.php`, `amphp.php`, `reactphp.php`,
`true_async.php`.
