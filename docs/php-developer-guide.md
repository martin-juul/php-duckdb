# Integrating DuckDB into a PHP application

Use this guide for application dependency checks and deployment. The existing
[overview](overview.md) covers installation, and the topic pages below cover
SQL and the PHP API. The [compatibility matrix](compatibility.md) records the
PHP and DuckDB versions for extension 1.2.2; see [migrations](migrations.md)
before upgrading an existing application.

## Declare the native dependency

DuckDB's PHP classes come from a loaded native extension. Composer autoloading
does not load `duckdb.so`, and installing a Composer event-loop package does
not install this extension or `libduckdb`.

Add the platform requirement to your application's existing `composer.json`
`require` object:

```json
{
    "php": ">=8.2",
    "ext-duckdb": "^1.2.2"
}
```

This is an application dependency declaration, not the extension's own
package manifest. Install the extension using the [PIE or source-build
instructions](overview.md), then run `composer check-platform-reqs` in the
runtime image. Composer checks the extension version; it does not check the
linked DuckDB library version.

Use the [loaded-version check](compatibility.md) to verify the extension and
engine separately during application bootstrap or deployment.

## Verify the PHP runtime that serves the application

CLI, PHP-FPM, and embedded PHP can use different executables and configuration
files. A successful CLI check verifies only that CLI installation. Run the
[loaded-version check](compatibility.md) through the application's actual SAPI during deployment
or in an internal health check.

Build with the target runtime's `phpize` and `php-config`; match its PHP
extension API, architecture, and ZTS setting. Keep `libduckdb` available to the
runtime dynamic loader after the build stage. For embedded ZTS PHP, follow the
[FrankenPHP build instructions](frankenphp.md#building-zts-and-version-match).

After changing `duckdb.so`, `libduckdb`, or the extension's INI configuration,
restart PHP-FPM and any persistent PHP application workers so they load the
new binaries and configuration. Recreate affected containers when deploying
an image. Check the reported versions in the restarted application before
sending it traffic; restarting a CLI process does not update running workers.

## Use the existing API guides in application code

| Application concern | Guide and runnable example |
|---|---|
| Database ownership, connection lifetime, file locks, multiple processes | [Connections](connect.md), [FrankenPHP worker lifetime](frankenphp.md#worker-mode) |
| User-supplied values and reusable statements | [Prepared statements](prepared.md), [prepared.php](../examples/prepared.php) |
| Buffered versus streaming reads, consuming results, interleaving | [Queries](query.md#streaming-queries-constant-memory) |
| Atomic changes and transaction errors | [transactions.php](../examples/transactions.php) |
| Decimal precision, dates, binary values, nested data | [Types](types.md) |
| Bulk imports, flushing, recovering from appender failures | [Appender](appender.md), [appender.php](../examples/appender.php) |
| Typed database errors and PHP argument errors | [Errors](errors.md), [errors.php](../examples/errors.php) |
| Async mode, connection allocation, cancellation, scheduler dependencies | [Async queries](async.md), [async examples](async.md#fan-out-example) |

Check method signatures against the [PHP API reference](api.md) or the
[canonical stub](../duckdb.stub.php) when adapting framework code. This
extension exposes `DuckDB\Database`, `Connection`, and related classes;
framework database adapters need to call that API explicitly.
