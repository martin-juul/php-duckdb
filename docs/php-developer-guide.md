# Integrating DuckDB into a PHP application

When deploying an application that uses DuckDB, check both its native
dependencies and the PHP runtime serving it. This guide covers those checks.
The [overview](overview.md) covers installation, and the topic pages below
cover SQL and the PHP API. For extension 1.3.1, the
[compatibility matrix](compatibility.md) records the PHP and DuckDB versions;
read [migrations](migrations.md) before upgrading an existing application.

## Declare the native dependency

DuckDB's PHP classes come from a loaded native extension. Composer autoloading
does not load `duckdb.so`, and installing a Composer event-loop package does
not install this extension or `libduckdb`.

Add the platform requirement to your application's existing `composer.json`
`require` object:

```json
{
    "php": ">=8.2",
    "ext-duckdb": "^1.3.1"
}
```

This declaration belongs to the application, not the extension's own package
manifest. First install the extension using the [PIE or source-build
instructions](overview.md), then run `composer check-platform-reqs` in the
runtime image. Composer checks the extension version; the linked DuckDB
library version needs a separate check.

Use the [loaded-version check](compatibility.md) to verify the extension and
engine separately during application bootstrap or deployment.

For Windows, use the [ZIP installation guide](../packaging/windows/README.md)
and select the TS/NTS and compiler variant matching the actual deployment
runtime. Do not copy a Unix `.so` or a DLL built for a different PHP minor.

## Verify the PHP runtime that serves the application

A successful CLI check verifies the CLI installation only. PHP-FPM and
embedded PHP can use different executables and configuration files. During
deployment or an internal health check, run the
[loaded-version check](compatibility.md) through the application's actual SAPI.

Build with the target runtime's `phpize` and `php-config`; match its PHP
extension API, architecture, and ZTS setting. Keep `libduckdb` available to the
runtime dynamic loader after the build stage. For embedded ZTS PHP, follow the
[FrankenPHP build instructions](frankenphp.md#building-zts-and-version-match).

After changing `duckdb.so`, `libduckdb`, or the extension's INI configuration,
restart PHP-FPM and any persistent PHP application workers. They load the new
binaries and configuration at startup; restarting a CLI process does not
update them. Recreate affected containers when deploying an image, and check
the reported versions in the restarted application before sending it traffic.

## Use the existing API guides in application code

| Application concern | Guide and runnable example |
| --- | --- |
| Database ownership, connection lifetime, file locks, multiple processes | [Connections](connect.md), [FrankenPHP worker lifetime](frankenphp.md#worker-mode) |
| User-supplied values and reusable statements | [Prepared statements](prepared.md), [prepared.php](../examples/prepared.php) |
| Buffered versus streaming reads, consuming results, interleaving | [Queries](query.md#streaming-queries-constant-memory) |
| Atomic changes and transaction errors | [transactions.php](../examples/transactions.php) |
| Decimal precision, dates, binary values, nested data | [Types](types.md) |
| Bulk imports, flushing, recovering from appender failures | [Appender](appender.md), [appender.php](../examples/appender.php) |
| Typed database errors and PHP argument errors | [Errors](errors.md), [errors.php](../examples/errors.php) |
| Async mode, connection allocation, cancellation, scheduler dependencies | [Async queries](async.md), [async examples](async.md#fan-out-example) |

When adapting framework code, check method signatures against the
[PHP API reference](api.md) or the [canonical stub](../duckdb.stub.php).
Framework database adapters need to call the extension's API explicitly:
`DuckDB\Database`, `Connection`, and the related classes.
