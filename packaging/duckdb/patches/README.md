# DuckDB engine patches

This repository carries two DuckDB source patches. SDK builds apply them
before compiling `libduckdb`; both are included in the libraries shipped by
those builds. The PHP extension still links through DuckDB's public C API.

## Patch inventory

| Patch | Source baseline | Purpose | Origin and upstream tracking |
| --- | --- | --- | --- |
| [nullable-bitpacking.patch](nullable-bitpacking.patch) | DuckDB 1.5.6, pinned in [source.json](../source.json) | Initialize NULL slots before frame-of-reference bitpacking | Local fix developed for this repository; no upstream issue, PR or merge reference is recorded here |
| [arrow-geometry.patch](arrow-geometry.patch) | DuckDB 1.5.6, pinned in [source.json](../source.json) | Run C Arrow conversions in a transaction and preserve declared geometry CRS on import | Local fix developed for this repository; no upstream issue, PR or merge reference is recorded here |

The patches are maintained against this exact source baseline. Their presence does
not establish whether another DuckDB release contains the same defect or an
equivalent fix. The general PHP extension support for DuckDB 1.5.5 does not
mean these patches have been validated against 1.5.5.

## Nullable bitpacking

When DuckDB compresses nullable integers, NULL slots can remain uninitialized
in the compression buffer. The bitpacking path subtracts a frame of reference
from every slot and persists the packed bytes, including those unused slots.
Memcheck detects the resulting uninitialized writes during checkpoint or close.
The [minimal reproducer](../../../tests/native/nullable_bitpacking.c) triggers
this using only the DuckDB C API, independently of PHP.

The patch sets each invalid slot to `frame_of_reference` before subtraction.
Its packed value therefore becomes a defined zero. Setting the slot to zero
before subtraction would be incorrect for a nonzero frame and could produce
an out-of-range value. The validity mask continues to represent SQL NULLs.

This changes an internal compression implementation, with no public API or ABI
change. It retains bitpacking and does not apply a database-wide compression
workaround. It does not rewrite existing database files.

## Arrow conversion and geometry CRS

The C Arrow schema and array conversion entry points consult catalog-backed
extension types. The original pinned engine does not consistently supply an
implicit transaction for those lookups. Some work also occurs outside its C
exception boundary. The patch wraps conversion in `RunFunctionInTransaction`
and reports failures through `duckdb_error_data`, including errors encountered
while collecting extension types and allocating conversion state. Explicit
caller transactions remain in control when already active. Expected input
and conversion validation failures are reported without invalidating an
existing caller transaction; severe engine failures retain normal DuckDB
transaction semantics.

The original Arrow geometry importer can discard a declared CRS when its
coordinate catalog cannot resolve the identifier. Catalog-resolved CRS can
already roundtrip on that engine. The patch preserves a representable
declared CRS from Arrow extension metadata without requiring catalog
resolution and rejects unsupported descriptions. Export CRS resolution errors
are reported rather than silently omitted from metadata. The
PHP extension continues to use only DuckDB's public C API; the patch changes
engine internals without adding a PHP dependency on C++ headers.

The [native regression](../../../tests/native/arrow_geometry.cpp) covers known
and noncatalog CRS definitions, nested fields, appending to a matching CRS
table, and caller transactions across conversion failures. It passes normally
and under Valgrind with zero errors or lost allocations. The PHP 8.4 full
harness passes 92 PHPTs and 91 Memcheck tests, including the
[lossless regression](../../../tests/094_arrow_lossless.phpt),
[foreign CRS metadata](../../../tests/095_arrow_geometry_metadata.phpt), and
[failed-import cleanup](../../../tests/096_arrow_failure_cleanup.phpt).
See [compatibility](../../../docs/compatibility.md) for exact runtime skips
and the [Arrow conversion contract](../../../docs/arrow.md).

## Which builds include them

The POSIX, Windows and Solaris SDK builders apply both patches. Docker images and
packages that build a vendored SDK consequently ship a patched engine. Builds
linked to an external or distribution-provided `libduckdb` use that library as
provided; the PHP extension build does not patch it. See the
[packaging strategy](../../README.md#libduckdb-strategy) for each package's source.

The source archive is pinned by hash, and patch application must succeed
cleanly. Installed SDKs carry `source.json`, `nullable-bitpacking.patch`,
`arrow-geometry.patch`, build metadata and DuckDB's license under
`share/duckdb-sdk/`. Both patch hashes enter the SDK identity, and cache
verification requires both artifacts and their checksums. Use that metadata
to identify our build: the patches do not change DuckDB's reported `1.5.6`
version.

## Nullable bitpacking evidence and patch removal

The [62-case native regression](../../../tests/native/nullable_bitpacking_matrix.c)
checks values and NULL positions after checkpoint and reopen. Its header gives
compile and Memcheck commands. The same workload reported 49 Memcheck errors
with the original 1.5.6 SDK and zero with the patched SDK. SQL assertions alone
do not detect undefined bytes in unused NULL slots. The full PHP 8.4 harness
also passed all 79 Memcheck tests with the patched SDK; details are in
[compatibility](../../../docs/compatibility.md#memcheck-and-nullable-integer-persistence).

When upgrading DuckDB, check for an equivalent upstream fix and record its issue
or commit reference here. Run the native regression under Memcheck against the
candidate unpatched engine, then run the full PHP harness. Remove the local
patch and its builder references when that validation confirms the engine no
longer needs it. A patch application failure alone is not evidence of a fix.
