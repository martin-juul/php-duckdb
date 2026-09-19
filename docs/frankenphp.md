# FrankenPHP

[FrankenPHP](https://frankenphp.dev) runs PHP inside a Go application server
(Caddy), either in classic mode or in **worker mode**, where your script
boots once per worker thread and then serves requests in a loop via
`frankenphp_handle_request()`.

The extension is fully compatible with both modes. Its ZTS-safety is
verified against FrankenPHP's embedded ZTS PHP in CI, including a
worker-mode smoke test under concurrent load and a graceful-shutdown
check.

## Building: ZTS and version match

FrankenPHP embeds a **ZTS** (thread-safe) PHP build. A shared extension
must be compiled against the **same PHP minor version** (e.g. 8.4.x) with
**ZTS enabled**, otherwise it will refuse to load ("unable to load dynamic
library" / API-number mismatch). FrankenPHP prints its embedded PHP version
at startup (`FrankenPHP started 🐘 ... php_version: 8.5.10`); check which
version a release embeds on the
[releases page](https://github.com/php/frankenphp/releases).

The easiest route is the official builder image:

```dockerfile
FROM dunglas/frankenphp:php8.5-builder AS builder

# libduckdb v1.5.x
RUN curl -sL https://github.com/duckdb/duckdb/releases/download/v1.5.5/libduckdb-linux-amd64.zip -o /tmp/libduckdb.zip \
 && unzip -o /tmp/libduckdb.zip -d /opt/duckdb \
 && mkdir -p /opt/duckdb/include /opt/duckdb/lib \
 && mv /opt/duckdb/duckdb.h /opt/duckdb/include/ \
 && mv /opt/duckdb/libduckdb.so /opt/duckdb/lib/

# the image's phpize/php-config are the ZTS build FrankenPHP uses
COPY . /src
RUN cd /src \
 && phpize \
 && ./configure --with-duckdb=/opt/duckdb \
 && make -j"$(nproc)"

FROM dunglas/frankenphp:php8.5
COPY --from=builder /src/modules/duckdb.so /usr/local/lib/php/extensions/duckdb.so
COPY --from=builder /opt/duckdb/lib/libduckdb.so /usr/local/lib/
RUN echo 'extension=duckdb.so' > /usr/local/etc/php/conf.d/duckdb.ini \
 && ldconfig
```

Building by hand instead: configure PHP with `--enable-zts`, then build
the extension with that build's `phpize`/`php-config`.

## Worker mode

```php
<?php
use DuckDB\Database;

$db = new Database(':memory:');   // worker scope: created once per thread
$conn = $db->connect();
$conn->query('CREATE TABLE hits (path VARCHAR, n BIGINT)');

$handler = static function () use ($conn) {
    $conn->execute('INSERT INTO hits VALUES (?, 1)', [$_SERVER['REQUEST_URI'] ?? '/']);
    echo json_encode($conn->query('SELECT sum(n) AS t FROM hits')->fetchAll()[0]);
};

while (frankenphp_handle_request($handler)) {
}
```

A runnable version ships as [examples/frankenphp.php](../examples/frankenphp.php)
with [examples/frankenphp.Caddyfile](../examples/frankenphp.Caddyfile).

Things to know:

- **Worker-scope objects persist across requests.** A `Database` created
  outside the request handler lives for the worker thread's lifetime —
  a `:memory:` database becomes a per-worker-thread in-memory cache.
  Each worker thread has its own set; there is no cross-worker sharing.
- **One connection per worker is enough.** FrankenPHP serves one request
  per thread at a time, and the driver serializes DuckDB calls per
  connection internally.
- **`queryAsync()` works in workers.** The background thread never touches
  PHP state, so it is unaffected by the surrounding request lifecycle.
  `PendingQuery::suspend()` additionally integrates with Swoole/AMPHP/
  ReactPHP if the worker script runs an event loop — see
  [async.md](async.md).
- **Abandoned async queries are safe.** If a request ends while an async
  query is still running, the C++ task completes independently; at server
  shutdown the driver interrupts and waits out any in-flight workers
  before PHP may unload the extension — there is no dlclose race.
- **`max_requests` restarts are safe.** If you configure worker restarts,
  request-scoped destructors run normally; the driver is Valgrind-clean
  across create/destroy cycles.
