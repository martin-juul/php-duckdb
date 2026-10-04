# FrankenPHP

With [FrankenPHP](https://frankenphp.dev), your PHP application runs inside
the Go application server Caddy. Classic mode runs individual requests;
**worker mode** boots your script once per worker thread, then serves requests
in a loop through `frankenphp_handle_request()`.

The extension supports both modes. CI verifies its ZTS-safety against
FrankenPHP's embedded ZTS PHP, with a worker-mode smoke test under concurrent
load and a graceful-shutdown check.

## Prebuilt images

The GitHub Container Registry publishes FrankenPHP images with the extension
preinstalled for `linux/amd64` and `linux/arm64`. They are built from
[`Dockerfile.frankenphp`](../Dockerfile.frankenphp):

```bash
docker run --rm --entrypoint php \
  ghcr.io/martin-juul/php-duckdb:8.5-frankenphp \
  -r 'var_dump(DuckDB\version());'
```

Choose a PHP version, extension release, or both:

| Tag examples                                   | Selection                                                        |
| ---------------------------------------------- | ---------------------------------------------------------------- |
| `8.4-frankenphp`, `php8.4-frankenphp`          | Moving PHP 8.4 build; PHP 8.5 is also available                  |
| `latest-frankenphp`                            | Moving build for the newest supported PHP version, currently 8.5 |
| `1.3.1-php8.4-frankenphp`                      | Extension release 1.3.1 with PHP 8.4                             |
| `1.3-php8.4-frankenphp`, `1-php8.4-frankenphp` | Moving minor/major release aliases with PHP 8.4                  |
| `1.3.1-frankenphp`                             | Extension release 1.3.1 with PHP 8.5                             |
| `1.3-frankenphp`, `1-frankenphp`               | Moving minor/major release aliases with PHP 8.5                  |

Master pushes and stable release builds update the PHP-only tags. Git tags
produce the release tags, and minor/major aliases follow the most recently
published matching release build. Prereleases publish only full-version tags,
such as `1.4.0-rc.1-php8.4-frankenphp` and `1.4.0-rc.1-frankenphp`; they do not
change stable aliases. Pull requests do not publish images.

Use one of these images as your application base and enable worker mode with
the environment variable shown below:

```dockerfile
FROM ghcr.io/martin-juul/php-duckdb:8.5-frankenphp
COPY . /app
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
```

## Building: ZTS and version match

When building the extension yourself, match FrankenPHP's embedded PHP build.
FrankenPHP uses **ZTS** (thread-safe) PHP. A shared extension must be compiled
against the **same PHP minor version** (e.g. 8.4.x) with **ZTS enabled**. A
mismatch prevents loading ("unable to load dynamic library" / API-number
mismatch).

FrankenPHP prints its embedded PHP version at startup
(`FrankenPHP started 🐘 ... php_version: 8.5.10`). Check the
[releases page](https://github.com/php/frankenphp/releases) for the PHP version
embedded in a release.

Use the maintained [Dockerfile](../Dockerfile.frankenphp), which matches the
builder and runtime PHP versions and builds the patched DuckDB engine:

```sh
docker build -f Dockerfile.frankenphp --build-arg PHP_VERSION=8.5 \
    -t php-duckdb-frankenphp .
```

The [SDK builder](../packaging/duckdb/README.md) pins the engine source and
patch. No DuckDB C++ client headers are included in the PHP extension build.

For a manual build, configure PHP with `--enable-zts`, then use that build's
`phpize`/`php-config` to build the extension.

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

A runnable version ships as
[examples/frankenphp.php](../examples/frankenphp.php) with
[examples/frankenphp.Caddyfile](../examples/frankenphp.Caddyfile).

Things to know:

- **Worker-scope objects persist across requests.** A `Database` created outside
  the request handler lives for the worker thread's lifetime. A `:memory:`
  database therefore acts as an in-memory cache for that worker thread. Each
  worker thread has its own set of objects; there is no cross-worker sharing.
- **One connection per worker is enough.** FrankenPHP serves one request per
  thread at a time, and the driver serializes DuckDB calls per connection
  internally.
- **`queryAsync()` works in workers.** The background thread never touches PHP
  state, so it is unaffected by the surrounding request lifecycle.
  `PendingQuery::suspend()` additionally integrates with Swoole/AMPHP/ ReactPHP
  if the worker script runs an event loop — see [async.md](async.md).
- **Abandoned async queries are safe.** If a request ends while an async query
  is running, the C++ task completes independently. At server shutdown, the
  driver interrupts and waits for any in-flight workers before PHP may unload
  the extension, avoiding a dlclose race.
- **`max_requests` restarts are safe.** If you configure worker restarts,
  request-scoped destructors run normally. The test suite exercises
  create/destroy cycles; see [validation scope](compatibility.md).
