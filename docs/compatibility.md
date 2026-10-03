# Compatibility

The PHP extension and the embedded DuckDB engine have separate versions. This
checkout uses extension **1.3.1**, and its pinned build recipes use **DuckDB
1.5.6**. Before upgrading, check the versions loaded by the actual process:

```php
printf("PHP %s; extension %s; DuckDB %s\n",
    PHP_VERSION, phpversion('duckdb'), DuckDB\version());
```

## PHP and platform coverage

The tables list configured checks; they do not claim that every job has passed
for this checkout. The job definitions are in [CI](../.github/workflows/ci.yml),
[packaging](../.github/workflows/packaging.yml), and
[Docker](../.github/workflows/docker.yml).

| PHP | Source CI | Docker | Local validation for this change |
| --- | --------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------- |
| 8.2 | Linux and macOS | amd64 and arm64 | Not run locally |
| 8.3 | Linux and macOS | amd64 and arm64 | Not run locally |
| 8.4 | Linux and macOS; PIE install smoke test | amd64 and arm64 | Linux x86_64, DuckDB 1.5.6: build passed; 81 PHPTs passed, 3 optional-runtime tests skipped |
| 8.5 | Linux and macOS | amd64 and arm64 | Linux x86_64, PHP 8.5.11 NTS, DuckDB 1.5.6: build passed; 81 PHPTs passed, 3 optional-runtime tests skipped |

AMPHP and ReactPHP tests also pass in a separate run with those dependencies
installed. An isolated CMake build with PHP 8.5.11 and DuckDB 1.5.5 passes 80
PHPTs, with four optional-runtime tests skipped. The CI TrueAsync image with
PHP 8.6 ZTS and DuckDB 1.5.6 passes 81 PHPTs, with three optional-runtime tests
skipped; a focused run confirms that the TrueAsync reactor test executes and
passes. All environments, including PHP 8.4, pass all 14 examples and both
stress scripts.

| Packaging target | PHP selection | DuckDB source | Architectures |
| -------------------- | ---------------------------- | ---------------------- | ------------- |
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

Windows packaging uses `windows-2022` with the matching official PHP
toolchains: VS16 for PHP 8.2/8.3 and VS17 for PHP 8.4/8.5. Each of these eight
combinations must pass its Windows packaging job before it is considered
tested. Local Linux tests do not verify Windows binaries. See the
[Windows installation guide](../packaging/windows/README.md).

A PHP extension binary must match the target PHP module ABI, architecture, and
thread-safety configuration; rebuild when those change.

Windows supports queries, prepared statements, appenders, polling, background
execution, Fibers, and selectable completion streams. The completion channel
uses loopback Winsock sockets. Swoole is unavailable on Windows; True Async
runtime builds are not part of this Windows matrix. AMPHP/ReactPHP are optional
application dependencies, not bundled in the ZIP. No Windows x86, ARM64, or
Valgrind coverage is claimed.

## DuckDB versions

| Engine | Status |
| ------------------------------ | ---------------------------------------------------------------------------------------------------- |
| 1.5.6 | Current pinned target; matching headers and library required |
| 1.5.5 | Supported distribution baseline; typed binding, examples and stress tests pass |
| Distribution library | Version follows the package repository; its configured build/test job must validate that combination |
| Other older or future versions | Unverified; build and run the suite before deployment |

The build checks for the required C API functions. It does not require the
version macros introduced in DuckDB 1.5.6: DuckDB 1.5.5 already provides the
expression folding, scalar bind and geometry CRS APIs used by typed binding.
Debian system-library packaging requires `libduckdb-dev >= 1.5.5`; older
versions are unverified.

## C API audit: 1.5.5 → 1.5.6

Comparing the tagged
[1.5.5 header](https://github.com/duckdb/duckdb/blob/v1.5.5/src/include/duckdb.h)
and
[1.5.6 header](https://github.com/duckdb/duckdb/blob/v1.5.6/src/include/duckdb.h)
finds 546 function declarations in each: no functions added or removed, and no
functional signature changes. Thirteen empty parameter lists become `(void)`.
The generated header introduces version/surface controls and records **142
formerly unstable functions as stable**. Twelve were already used by this
driver: instance caching, table-name discovery, prepared column metadata, and
structured appender/error reporting.

| Newly stable family | PHP binding status in 1.3.1 |
| ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| Instance cache, table-name discovery, prepared metadata, error data | Already used internally or exposed through existing methods |
| Appender clear | Exposed through `Appender::clear()` for explicit discard and recovery |
| Query appenders, appender default-to-chunk | Additional capability requiring explicit binding work |
| Client context, catalog, configuration options, file-system handles | Additional capability requiring explicit binding work |
| Arrow schema/chunk conversion | No Arrow PHP binding |
| Typed values and value constructors | Full typed input through native PHP value classes; C value handles remain internal |
| Geometry CRS | Typed geometry preserves CRS metadata; additional CRS APIs are not public |
| Scalar bind callbacks and expressions | Used internally for typed conversion; no public PHP callback or expression-handle API |
| COPY functions, scalar init callbacks, table-function metadata, custom logging | No corresponding PHP callback/handle surface |
| Standalone vectors, selection vectors, value string rendering, UTF-8 checks | Additional capability requiring explicit binding work |

[Native typed value classes](value.md) provide complete typed input for
prepared statements, execution parameter arrays and Appender, including
geometry with CRS metadata. Their internal conversion path uses a private
native scalar bind callback and expression folding; it does not expose PHP UDF
callbacks or expression handles. Stabilization alone does not make every C
handle a PHP API. General value string rendering and the other planned public
APIs remain in the [roadmap](roadmap.md). The PHP surface is specified in
[the stub](../duckdb.stub.php) and [API reference](api.md).

The
[DuckDB 2.0 API spellings backport](https://github.com/duckdb/duckdb/pull/24852)
adds internal C++ interfaces for DuckDB engine extensions, not functions to the
C API used by this PHP driver. They need no PHP aliases. The new
[`enable_optimistic_write` setting](config.md) is already available through PHP
configuration and SQL. Engine query, storage, and Parquet fixes are picked up by
linking 1.5.6; they do not require separate PHP methods.

The four old Arrow wrapper types (`duckdb_arrow`, `duckdb_arrow_stream`,
`duckdb_arrow_schema`, `duckdb_arrow_array`) become deprecated in 1.5.6. They
have no PHP counterpart here, so this creates no new PHP deprecation.
`duckdb_varint`, `duckdb_get_varint`, and `duckdb_create_varint` are historical
aliases for BIGNUM names, renamed in 1.4.0; they are not new 1.5.6 features and
are only visible when targeting the older API version in the new header.

Two deprecated C functions remain internal dependencies:

| C function | Purpose | Strategy |
| ----------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `duckdb_pending_prepared_streaming` | Streaming prepared queries | Preserve streaming behavior until an equivalent supported execution path is validated |
| `duckdb_row_count` | Materialized `Result::rowCount()` | Retain the isolated call until a replacement preserves result/cursor semantics |

Upstream deprecated both functions in 1.0.0; their use does not deprecate
the PHP methods. New code should use supported C entry points where those
provide equivalent behavior. See [migration strategy](migrations.md).

BIGNUM and VARIANT result decoding uses C API accessors with algorithms adapted
from DuckDB's C++ implementation. The extension has no C++ client dependency.
Their internal storage formats are audited for exactly 1.5.5 and 1.5.6; other
versions raise a decoding error for these types until audited. The decoders
check layout and payload bounds and retain DuckDB's MIT attribution.

## Memcheck and nullable integer persistence

The full PHP 8.4 harness passes with the patched DuckDB 1.5.6 SDK: 81 PHPTs
pass with three optional-runtime skips, all 79 Memcheck tests pass without
warnings or errors, and all 14 examples and both stress scripts pass. Five
tests retain their existing Memcheck exclusions. This run uses the final SDK
with all five built-in extensions, including autocomplete, under CI's two-CPU
quota. The native persistence matrix also passes under Memcheck.

DuckDB 1.5.6 writes uninitialized bitpacking bytes while persisting nullable
integers. The [standalone C reproducer](../tests/native/nullable_bitpacking.c)
uses only DuckDB's C API and reproduces the error without PHP or a C++ client.
The minimal SQL is:

```sql
CREATE TABLE ints AS
SELECT CASE WHEN i%3=0 THEN NULL ELSE i%100 END::UINTEGER AS v
FROM range(1000) t(i);
CHECKPOINT;
```

Applications can opt out of bitpacking by running this before writing data:

```sql
SET disabled_compression_methods = 'bitpacking';
```

The setting applies database-wide, including other connections. Passing it
only through the `Database` constructor is ineffective on DuckDB 1.5.5 and
1.5.6; apply it through SQL. SQL can also restore the default with
`SET disabled_compression_methods = ''`.

The SQL workaround eliminates the focused reproducer's Memcheck error, but
it affects compression for all columns. In a seven-run benchmark with 100,000
rows, an eight-column mixed table grows from 1,060,864 to 5,255,168 bytes
(4.95 times its default size). The extension therefore applies no default
workaround. Repository SDK builds instead apply a
[small engine patch](../packaging/duckdb/patches/nullable-bitpacking.patch):
invalid slots receive the group minimum before subtraction, so their packed
value is a defined zero. The same 62-case native regression reports 49
Memcheck errors with the original SDK and zero with the patched SDK, with no
lost allocations. Both persistence benchmarks retain their original file
sizes with the patch.

Vendored packages and Docker builds use this patched SDK. System-library
packages and manually supplied engines need an equivalent upstream or
distribution backport. The PHP extension continues to use only the C API.
