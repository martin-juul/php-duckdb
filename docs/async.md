# Asynchronous Queries

DuckDB has no async wire protocol — it is in-process — so "async" here means:
run the query **off the calling fiber/thread** and get notified on completion.
This driver offers two execution modes plus deep event-loop integration.

## The two modes

```php
// 1. Background thread: DuckDB executes on a worker thread (default, fastest)
$pending = $conn->queryAsync('SELECT * FROM big_table');
$pending = $conn->prepare('SELECT * FROM t WHERE id = ?')->executeAsync([$id]);

// 2. Polling mode: no threads at all; isReady()/await() execute the query
//    in small slices on the calling thread (good for dl()-restricted or
//    single-threaded event-loop environments)
$pending = $conn->queryPending('SELECT * FROM big_table');
```

Both return a `DuckDB\PendingQuery`. The result is consumable **exactly once**.

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

Every handle carries a completion notification: a byte is written when the
query finishes.

```php
$fd = $pending->getFd();          // int, for uv_poll() etc.; -1 in polling mode
$stream = $pending->getStream();  // PHP stream resource for stream_select(); once only
```

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
|---|---|
| **Swoole 6+** (inside a coroutine) | Yields the coroutine on the completion fd via Swoole's scheduler |
| **True Async** php-src fork | Parks in the libuv reactor; coroutine cancellation interrupts the query and `\Cancellation` escapes |
| **AMPHP v3** (Revolt loop) | Suspends the fiber on the loop; works even at script top level |
| **ReactPHP** (`react/async` v4+) | Awaits a promise on the React loop; cancelling the surrounding `async()` interrupts the query (`\RuntimeException` escapes). v3 is deliberately not engaged |
| **Generic fibers** | `Fiber::suspend()` loop; resume from your own scheduler via `getStream()` readability |

No configuration is needed: detection is purely by which classes/extensions
are loaded at runtime.

## Cancelling

```php
$pending->cancel();   // the query fails with InterruptedException
```

## Progress and interruption from elsewhere

```php
$conn->queryProgress();   // ['percentage' => 42.0, 'rowsProcessed' => …, 'totalRowsToProcess' => …]
$conn->interrupt();       // kill everything running on this connection
```

Both are safe to call from another thread/fiber — e.g. a watchdog fiber that
shows a progress bar or enforces a timeout.

## Fan-out example

```php
$pendings = [
    'users'  => $conn->queryAsync('SELECT * FROM users'),
    'orders' => $conn->queryAsync('SELECT * FROM orders'),
];

$results = [];
foreach ($pendings as $name => $p) {
    $results[$name] = $p->await()->fetchAll();   // both queries ran concurrently
}
```

For maximum parallelism give each async query its **own connection**
(`$db->connect()` is cheap) — statements on one connection serialize.

Runnable end-to-end scripts live in [`examples/`](../examples):
`async_concurrent.php`, `swoole.php`, `amphp.php`, `reactphp.php`,
`true_async.php`.
