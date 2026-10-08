# Compatibility

The PHP extension and the embedded DuckDB engine have separate versions. This
checkout uses extension **1.3.1**, and its pinned build recipes use **DuckDB
1.5.6**. Arrow APIs documented here are under development in this checkout
and are not included in the released 1.3.1 archive. Before upgrading, check
the versions loaded by the actual process:

```php
printf("PHP %s; extension %s; DuckDB %s\n",
    PHP_VERSION, phpversion('duckdb'), DuckDB\version());
```

## PHP and platform coverage

The tables list configured checks; they do not claim that every job has passed
for this checkout. The job definitions are in [CI](../.github/workflows/ci.yml),
[packaging](../.github/workflows/packaging.yml), and
[Docker](../.github/workflows/docker.yml).

| PHP | Source CI | Docker | Current local validation |
| --- | --------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------- |
| 8.2 | Linux and macOS | amd64 and arm64 | Amazon Linux 2023 x86_64, PHP 8.2.33: native package checks passed; 92 PHPTs passed, 4 optional-runtime tests skipped; 21 examples passed |
| 8.3 | Linux and macOS | amd64 and arm64 | Amazon Linux 2023 x86_64, PHP 8.3.33: native package checks passed; 92 PHPTs passed, 4 optional-runtime tests skipped; 21 examples passed |
| 8.4 | Linux and macOS; PIE install smoke test | amd64 and arm64 | Linux x86_64, PHP 8.4.26 NTS, DuckDB 1.5.6: full harness passed; 92 PHPTs passed, 4 optional-runtime tests skipped; 91 Valgrind tests passed |
| 8.5 | Linux and macOS | amd64 and arm64 | Linux x86_64, PHP 8.5.11 NTS, DuckDB 1.5.6: build and 93 PHPTs passed, 3 optional-runtime tests skipped; full host Valgrind run blocked by its internal Fiber crash |
| 8.6-dev | Linux True Async ZTS | CI image only | Exact True Async CI image: build and 93 PHPTs passed, 3 optional-runtime tests skipped; 21 examples passed |

The PHP 8.4 and 8.5 development environments passed all 21 example scripts
and both stress scripts,
including repeated Arrow ownership transfers. Optional framework examples
report a skip when their runtime is absent. The PHP 8.4 full run used an
isolated container with FFI enabled, 12 CPUs and 40 GiB RAM; eight workers
were allocated while the PHP 8.6 reproduction SDK built independently.
Swoole, True Async, AMPHP and
ReactPHP integration tests skipped there; the PHP 8.5 host ran Swoole and
skipped the other three.

The full Valgrind run retains five existing exclusions: streaming mid-flight
errors and the Swoole, True Async, AMPHP and ReactPHP integration tests. All
Arrow tests, including FFI exchange and sliced batches, ran under Memcheck.
No new exclusions or suppressions were added. The native geometry C API
regression passed normally and under Valgrind with zero errors and zero lost
allocations.

Host Valgrind 3.27.1 crashes internally in its stack unwinder during the
existing Fiber test. A separate PHP FFI/native-thread reproducer triggers the
same crash without loading DuckDB. The isolated PHP 8.4 run with Valgrind
3.19.0 passes the full suite, including that Fiber test. The host full
Valgrind run is therefore not a pass.

The PHP 8.6 reproduction uses `trueasync/php-true-async:0.7.13-php8.6`,
with its original FFI and True Async extensions, a four-CPU quota and three
workers. Its freshly built SDK fingerprint matches the failing CI job exactly.
The Arrow FFI failure-cleanup regression ran without skipping; Swoole, AMPHP
and ReactPHP were the three unavailable integrations. Arrow cleanup detects
whether the PHP headers provide the older saved-exception global, preserving
exception state on both that layout and the PHP 8.6 runtime without the field.

Earlier local validation before the Arrow changes also covered DuckDB 1.5.5
and AMPHP/ReactPHP dependencies. Those combinations have not been rerun locally
for Arrow in this checkout, and 1.5.5 is no longer supported.

| Packaging target | PHP selection | DuckDB source | Architectures |
| -------------------- | ---------------------------- | ---------------------- | ------------- |
| Debian sid | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| Debian 13 trixie | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| Ubuntu 24.04 / 26.04 | Distribution PHP (8.3 / 8.5) | Vendored 1.5.6 | amd64, arm64 |
| Ubuntu devel | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| Fedora 44 | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| AlmaLinux 9 / 10 | Remi PHP 8.2–8.5 | Vendored 1.5.6 | amd64, arm64 |
| Amazon Linux 2023 | Distribution PHP 8.2–8.5 | Vendored 1.5.6 | amd64, arm64 |
| Amazon Linux 2027 preview | Distribution PHP 8.5 | Vendored 1.5.6 | amd64, arm64 |
| openSUSE Tumbleweed | Distribution PHP | Vendored 1.5.6 | amd64, arm64 |
| macOS tarballs | PHP 8.2–8.5 | Vendored 1.5.6 | x86_64, arm64 |
| Windows ZIPs | PHP 8.2–8.5, TS and NTS | Vendored 1.5.6 | x64 |
| Docker | PHP 8.2–8.5 | Pinned 1.5.6 | amd64, arm64 |

Amazon Linux packaging uses the shared patched SDK. The 2027 target is an
[evaluation preview](https://docs.aws.amazon.com/linux/al2027/ug/container-base.html),
with [PHP 8.5](https://docs.aws.amazon.com/linux/al2027/ug/language-runtimes-php.html).
The Debian sid and Ubuntu devel private-engine packages passed native x86_64
builds, installation, loader/provenance checks and the installed-package smoke
test, with the system DuckDB package installed alongside them. Debian PHP
8.4.26 and Ubuntu PHP 8.5.9 each passed 92 PHPTs and all 21 examples against
the installed package with FFI enabled. Their native package builds each
passed 87 PHPTs, skipping five FFI tests subsequently covered by the installed
checks. Four optional runtime integrations were unavailable in each environment.

Amazon Linux native x86_64 validation passed for all five configurations:
AL2023 with PHP 8.2.33, 8.3.33, 8.4.25 and 8.5.10, and AL2027 preview with
PHP 8.5.10. Each passed 92 PHPTs and all 21 examples, with four optional
runtime skips. All five installed packages passed the patched-engine smoke
and private-library loader checks. Their aarch64 CI jobs have not been run
locally. Architectures and release targets not covered above still require
their own native validation.

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
| 1.5.5 and older | Not supported: COPY functions became stable C API in 1.5.6 |
| External library (manual source builds) | Validate the actual library separately; packaging recipes use the shared patched SDK |
| Other older or future versions | Unverified; build and run the suite before deployment |

The build checks for the required C API functions, including the COPY
function, client context and statement extraction APIs. It does not rely on
the version macros introduced in DuckDB 1.5.6, so distribution SDKs without
them still build when they provide those functions. The minimum supported
engine is 1.5.6, which stabilized the COPY function API.

All packaging recipes use the pinned patched SDK. Manual external-library
builds must provide the required APIs and validate Arrow transaction/CRS
behavior. They also lose two guarantees of [PHP COPY formats](copy.md), which
the bundled patch adds to DuckDB's binder:

- With the patch, a connection that did not register a format behaves as if
  it did not exist: `FORMAT name` gets DuckDB's missing-format error and a
  matching extension falls back to CSV. Without it, those statements fail with
  a `BinderException` saying the format is not registered on this connection.
- With the patch, `PARTITION_BY`, `PER_THREAD_OUTPUT` and `EXPORT DATABASE`
  are rejected when a statement is bound. Without it, they fail at runtime,
  when a second output file starts.

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

| Newly stable family | Current PHP binding status |
| ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| Instance cache, table-name discovery, prepared metadata, error data | Already used internally or exposed through existing methods |
| Appender clear | Exposed through `Appender::clear()` for explicit discard and recovery |
| Query appenders, appender default-to-chunk | Additional capability requiring explicit binding work |
| Client context, catalog, configuration options, file-system handles | Additional capability requiring explicit binding work |
| Arrow schema/chunk conversion | Exposed through `ArrowSchema`, `ArrowChunk` and `DataChunk`, with C Data Interface import/export and batch appending |
| Typed values and value constructors | Full typed input through native PHP value classes; C value handles remain internal |
| Geometry CRS | Typed geometry preserves CRS metadata; additional CRS APIs are not public |
| Scalar bind callbacks and expressions | Used internally for typed conversion; no public PHP callback or expression-handle API |
| COPY functions | `COPY ... TO` formats exposed through `Connection::registerCopyToFunction()`; `COPY ... FROM` functions are not public |
| Scalar init callbacks, table-function metadata, custom logging | No corresponding PHP callback/handle surface |
| Value string rendering | Exposed through `Value::toString(Connection)` for connection-aware display text |
| Standalone vectors | Exposed through `Vector`, `Connection::createVector()` and `DataChunk::fromVectors()`/`vector()` |
| Selection vectors | Exposed through `SelectionVector`, `Vector::select()`/`copySelected()` and `DataChunk::select()`; slicing into dictionary vectors is not public |
| UTF-8 checks | Used internally for string writes; no public PHP API |

[Native typed value classes](value.md) provide complete typed input for
prepared statements, execution parameter arrays and Appender, including
geometry with CRS metadata. Their internal conversion path uses a private
native scalar bind callback and expression folding; it does not expose PHP UDF
callbacks or expression handles. Stabilization alone does not make every C
handle a PHP API. Connection-aware [value string rendering](value.md#display-text)
is available through `Value::toString(Connection)`. [Arrow conversion](arrow.md)
provides schema and batch handles with native address exchange.
[Standalone vectors](vector.md) are owned, typed columns that build native
chunks; they do not expose raw buffers. [Selection vectors](selection.md)
pick, reorder and repeat vector and chunk rows by copying them.
[COPY TO formats](copy.md) let PHP classes write `COPY` output, with handlers
called on the request thread. The remaining
planned public APIs are listed in the [roadmap](roadmap.md). The PHP surface
is specified in [the stub](../duckdb.stub.php) and [API reference](api.md).

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

The current full PHP 8.4.26 harness passes with the patched DuckDB 1.5.6 SDK:
92 PHPTs pass with four optional-runtime skips, all 91 Memcheck tests pass,
and all 21 example scripts and both stress scripts pass. Five existing Memcheck
exclusions remain. See the platform coverage above for the container and
host Valgrind limitations. Earlier native persistence validation also passed
under Memcheck.

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

All packaging recipes and Docker builds use this patched SDK. Manually
supplied external engines need an equivalent upstream or distribution
backport. The PHP extension continues to use only the C API.
See the [patch inventory](../packaging/duckdb/patches/README.md) for the exact
source baseline, upstream tracking and criteria for removing the local fix.
