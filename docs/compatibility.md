# Compatibility

The extension version and the embedded database version are separate. For this
checkout, the extension is **1.3.1** and pinned build recipes use **DuckDB
1.5.6**. Check the actual process before upgrading:

```php
printf("PHP %s; extension %s; DuckDB %s\n",
    PHP_VERSION, phpversion('duckdb'), DuckDB\version());
```

## PHP and platform coverage

These tables describe configured checks, not a claim that every job has passed
for this checkout. See [CI](../.github/workflows/ci.yml),
[packaging](../.github/workflows/packaging.yml), and
[Docker](../.github/workflows/docker.yml).

| PHP | Source CI | Docker | Local validation for this change |
|---|---|---|---|
| 8.2 | Linux and macOS | amd64 and arm64 | Not run locally |
| 8.3 | Linux and macOS | amd64 and arm64 | Not run locally |
| 8.4 | Linux and macOS; PIE install smoke test | amd64 and arm64 | Not run locally |
| 8.5 | Linux and macOS | amd64 and arm64 | Linux x86_64, PHP 8.5.11: build, 62 tests passed; 3 optional-runtime tests skipped; 12 example scripts completed |

| Packaging target | PHP selection | DuckDB source | Architectures |
|---|---|---|---|
| Debian sid | Distribution PHP | System `libduckdb-dev` | amd64, arm64 |
| Debian 13 trixie | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| Ubuntu 24.04 / 26.04 | Distribution PHP (8.3 / 8.5) | Vendored 1.5.6 | amd64, arm64 |
| Ubuntu devel | Distribution PHP | System `libduckdb-dev` | amd64, arm64 |
| Fedora 44 | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| AlmaLinux 9 / 10 | Remi PHP 8.2–8.5 | Vendored 1.5.6 | amd64, arm64 |
| openSUSE Tumbleweed | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| macOS tarballs | PHP 8.2–8.5 | Vendored 1.5.6 | x86_64, arm64 |
| Windows ZIPs | PHP 8.2–8.5, TS and NTS | Vendored 1.5.6 | x64 |
| Docker | PHP 8.2–8.5 | Pinned 1.5.6 | amd64, arm64 |

Windows packaging uses `windows-2022` and matching official PHP toolchains:
VS16 for PHP 8.2/8.3 and VS17 for PHP 8.4/8.5. These eight combinations must
pass their Windows packaging jobs before being considered tested; local Linux
tests do not verify Windows binaries. See the
[Windows installation guide](../packaging/windows/README.md).

A PHP extension binary must match the target
PHP module ABI, architecture, and thread-safety configuration; rebuild when
those change.

Windows supports queries, prepared statements, appenders, polling, background
execution, Fibers, and selectable completion streams. The completion channel
uses loopback Winsock sockets. Swoole is unavailable on Windows; True Async
runtime builds are not part of this Windows matrix. AMPHP/ReactPHP are optional
application dependencies, not bundled in the ZIP. No Windows x86, ARM64, or
Valgrind coverage is claimed.

## DuckDB versions

| Engine | Status |
|---|---|
| 1.5.6 | Current pinned target; matching headers and library required |
| 1.5.5 | Previous pinned target and previously tested baseline; not the current release target |
| Distribution library | Version follows the package repository; its configured build/test job must validate that combination |
| Other older or future versions | Unverified; build and run the suite before deployment |

Older metadata referring to `libduckdb >= 1.1` is not evidence that this source
builds or behaves correctly with every such version. This driver already uses
APIs that were unstable before 1.5.6. Do not infer a supported minimum from the
stable history of individual functions.

## C API audit: 1.5.5 → 1.5.6

Comparing the tagged [1.5.5 header](https://github.com/duckdb/duckdb/blob/v1.5.5/src/include/duckdb.h)
and [1.5.6 header](https://github.com/duckdb/duckdb/blob/v1.5.6/src/include/duckdb.h)
finds 546 function declarations in each: no functions added or removed, and no
functional signature changes. Thirteen empty parameter lists become `(void)`.
The generated header introduces version/surface controls and records **142
formerly unstable functions as stable**. Twelve were already used by this
driver: instance caching, table-name discovery, prepared column metadata, and
structured appender/error reporting.

| Newly stable family | PHP binding status in 1.3.1 |
|---|---|
| Instance cache, table-name discovery, prepared metadata, error data | Already used internally or exposed through existing methods |
| Query appenders, appender clear/default-to-chunk | Additional capability requiring explicit binding work |
| Client context, catalog, configuration options, file-system handles | Additional capability requiring explicit binding work |
| Arrow schema/chunk conversion | No Arrow PHP binding |
| COPY functions, scalar bind/init callbacks, table-function metadata, expressions, custom logging | No corresponding PHP callback/handle surface |
| Standalone vectors, selection vectors, value constructors/string rendering, UTF-8 checks, geometry CRS | Additional capability requiring explicit binding work |

Stabilization alone does not make every C handle a PHP API. This patch does not
add bindings for previously unexposed API families. The PHP surface is specified
in [the stub](../duckdb.stub.php) and [API reference](api.md).

The [DuckDB 2.0 API spellings backport](https://github.com/duckdb/duckdb/pull/24852)
adds internal C++ interfaces for DuckDB engine extensions, not functions to the
C API used by this PHP driver. They need no PHP aliases. The new
[`enable_optimistic_write` setting](config.md) is already available through
PHP configuration and SQL. Engine query, storage, and Parquet fixes are picked
up by linking 1.5.6; they do not require separate PHP methods.

The four old Arrow wrapper types (`duckdb_arrow`, `duckdb_arrow_stream`,
`duckdb_arrow_schema`, `duckdb_arrow_array`) become deprecated in 1.5.6. They
have no PHP counterpart here, so this creates no new PHP deprecation.
`duckdb_varint`, `duckdb_get_varint`, and `duckdb_create_varint` are historical
aliases for BIGNUM names, renamed in 1.4.0; they are not new 1.5.6 features and
are only visible when targeting the older API version in the new header.

Three deprecated C functions remain internal dependencies:

| C function | Purpose | Strategy |
|---|---|---|
| `duckdb_pending_prepared_streaming` | Streaming prepared queries | Preserve streaming behavior until an equivalent supported execution path is validated |
| `duckdb_row_count` | Materialized `Result::rowCount()` | Retain the isolated call until a replacement preserves result/cursor semantics |
| `duckdb_value_varchar` | String fallback for types without a public vector layout | Retain the isolated fallback until arbitrary cell rendering has a validated replacement |

All three were deprecated upstream in 1.0.0. Their use does not deprecate the
PHP methods. New code should use supported C entry points where they provide
equivalent behavior. See [migration strategy](migrations.md).
