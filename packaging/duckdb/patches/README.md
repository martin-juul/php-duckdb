# DuckDB engine patches

This repository carries four DuckDB source patches. SDK builds apply them
before compiling `libduckdb`; all are included in the libraries shipped by
those builds. The PHP extension still links through DuckDB's public C API.

## Patch inventory

| Patch | Source baseline | Purpose | Origin and upstream tracking |
| --- | --- | --- | --- |
| [nullable-bitpacking.patch](nullable-bitpacking.patch) | DuckDB 2.0.0, pinned in [source.json](../source.json) | Initialize NULL slots before frame-of-reference bitpacking | Local fix developed for this repository; not fixed upstream in 2.0.0; no upstream issue, PR or merge reference is recorded here |
| [arrow-geometry.patch](arrow-geometry.patch) | DuckDB 2.0.0, pinned in [source.json](../source.json) | Run C Arrow conversions in a transaction and preserve declared geometry CRS on import | Local fix developed for this repository; not fixed upstream in 2.0.0; no upstream issue, PR or merge reference is recorded here |
| [c-api-copy-functions.patch](c-api-copy-functions.patch) | DuckDB 2.0.0, pinned in [source.json](../source.json) | Reject parallel COPY modes and EXPORT DATABASE for C API copy functions; let their bind callbacks decline a binding | Local change developed for this repository; not in upstream 2.0.0; no upstream issue, PR or merge reference is recorded here |
| [array-join-gather.patch](array-join-gather.patch) | DuckDB 2.0.0, pinned in [source.json](../source.json) | Store ARRAY values gathered by a hash join at the matched rows, not the first rows of the output | Local fix developed for this repository; not fixed upstream in 2.0.0; no upstream issue, PR or merge reference is recorded here |

The patches are maintained against this exact source baseline. The first
three were rebased from DuckDB 1.5.6 onto 2.0.0, where the C API sources moved
to `src/main/capi/v1/`; the ARRAY join gather patch was written against 2.0.0.
Each native regression below still fails on the unpatched 2.0.0 library, so
2.0.0 contains none of the fixes. Their presence does not
establish whether another DuckDB release contains the same defect or an
equivalent fix.

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
and under Valgrind with zero errors or lost allocations. The unpatched 2.0.0
library fails it with the internal error `TransactionContext::ActiveTransaction
called without active transaction`. With the patched 2.0.0 SDK, the PHP
harness passes the
[lossless regression](../../../tests/094_arrow_lossless.phpt),
[foreign CRS metadata](../../../tests/095_arrow_geometry_metadata.phpt), and
[failed-import cleanup](../../../tests/096_arrow_failure_cleanup.phpt).
See [compatibility](../../../docs/compatibility.md) for exact runtime skips
and the [Arrow conversion contract](../../../docs/arrow.md).

## C API copy functions

The original engine accepts `PARTITION_BY`, `PER_THREAD_OUTPUT` and
`EXPORT DATABASE` for copy functions registered with
`duckdb_register_copy_function`. Those modes create several global states or
run sinks concurrently, which a serial C API format cannot detect at bind
time. A registered name is also visible to every connection of the database,
and a bind callback has no way to say that the format does not apply to the
executing connection.

The patch identifies C API formats without a new flag or layout change:
`IsCAPICopyToFunction` checks whether a function's COPY TO bind is the C API
bind adapter, which only `duckdb_register_copy_function` installs. For such a
format, after its bind callback accepts the binding:

- `PARTITION_BY` raises the Binder Error
  `PARTITION_BY is not supported for C API copy function "<name>"`.
- `PER_THREAD_OUTPUT true` raises the matching `PER_THREAD_OUTPUT` Binder
  Error. `PER_THREAD_OUTPUT false` is accepted.
- `FILE_SIZE_BYTES` keeps the engine's Binder Error
  `FILE_SIZE_BYTES not implemented for "<name>"`, now raised after the bind
  callback.

`EXPORT DATABASE` with a C API format raises the Binder Error
`EXPORT DATABASE is not supported for C API copy function "<name>"` before
any table is bound. Built-in formats keep all of these modes.

A bind callback declines a binding by calling
`duckdb_copy_function_bind_set_error` with a message that starts with the
exact, case-sensitive text `[copy-function-declined]`. The engine releases any
bind data that the callback set and binds the statement as if the format were
not in the catalog:

- An explicit `FORMAT <name>` raises the Catalog Error
  `Copy Function with name <name> does not exist!`. It offers no suggestions
  and does not autoload an extension.
- A format inferred from the target's file extension falls back to CSV,
  including CSV option validation, `PARTITION_BY` and `FILE_SIZE_BYTES`.

Any other bind error, including one that contains the prefix elsewhere,
remains a Binder Error with the callback's message. A format must therefore
never begin its bind error with text that a user controls.

The patch adds no exported symbol, so callers still build and run against the
unpatched 2.0.0 library. There, a decline arrives as
`Binder Error: [copy-function-declined] ...`, and the parallel modes are
accepted; such callers need their own runtime detection. The
[native regression](../../../tests/native/c_api_copy_functions.c) covers the
rejections, explicit and inferred declines, the prefix position, and built-in
CSV and Parquet modes. Its header gives compile and Memcheck commands.
The unpatched 2.0.0 SDK fails 10 of its 24 checks. The patched SDK passes all
of them, normally and under Valgrind with zero errors or lost allocations.
DuckDB 2.0's new `ORDER_BY` copy option still drives a C API format through
one global state and serial sinks, so the patch leaves it allowed.
`BATCHES_PER_FILE` already fails for these formats because they implement no
batch callbacks.

## ARRAY join gather

A hash join stores the build side's rows in a row layout and gathers the
build columns of matched probe rows back into vectors. That gather cannot
produce fixed-size ARRAY vectors directly: for a column that contains an
ARRAY, the engine gathers the value as a LIST and then casts it to the ARRAY
type. The gather writes row `i` to output position `target_sel[i]`, but the
original cast converts output positions `0` to `count - 1`. When the target
selection is not the identity, it reads list entries that were never written
and never converts some of the gathered rows.

DuckDB 2.0.0 reaches this path with a non-identity selection in two places.
A LEFT JOIN whose build keys are unique gathers only the matched probe rows,
straight into their output positions; 2.0.0 added this path. A SINGLE join,
which DuckDB plans for a correlated scalar subquery, does the same; DuckDB
1.5.6 already fails such a subquery that returns an ARRAY, while its LEFT
JOIN works. A RIGHT JOIN that the optimizer turns into such a LEFT JOIN also
reaches the path. The
unwritten list entries make the query fail with
`Conversion Error: Cannot cast list with length 0 to array with length 2`,
with a garbage length, with an internal allocation error that invalidates
the database, or with a segmentation fault. Whether a query takes this path
depends on the plan: with the `join_order` optimizer disabled, the reproducer
builds its hash table on the other side and reads the ARRAY column from the
probe side, so it succeeds.

The patch gathers into positions `0` to `count - 1` of the temporary LIST
vector and casts those rows into a temporary ARRAY vector. It then copies
each row to its target position and leaves the other output rows untouched.
An identity selection keeps the original direct cast. The same change
applies to a STRUCT that contains an ARRAY, which the engine gathers as a
STRUCT of LISTs. This changes an engine-internal gather, with no public API,
ABI or storage format change.

The [native regression](../../../tests/native/array_join_gather.c) covers
unmatched rows at the start, middle and end of a chunk, NULL arrays and NULL
elements, VARCHAR, STRUCT, LIST and ARRAY elements, a STRUCT containing an
ARRAY, a LIST of ARRAYs, several chunks, chunks without any match, scalar
subqueries, and RIGHT, FULL, INNER and duplicate-key joins. Its header gives
compile and Memcheck commands. The unpatched 2.0.0 library fails 17 of its 23
checks in every run. Its LIST of ARRAYs case reads list entries that the
gather never wrote and passes, fails or crashes the process with a
segmentation fault, depending on the run. Memcheck reports no errors for these
stale reads on the unpatched library; the value checks detect them. The
FULL, INNER, duplicate-key, all-matched and last-row-unmatched cases do not
reach the faulty cast and pass there. The patched SDK passes all 23, normally
and under Valgrind with zero errors or lost allocations. With the patched SDK,
the PHP harness passes the
[LEFT JOIN regression](../../../tests/152_array_left_join.phpt), which fetches
the original reproducer and a multi-chunk nested ARRAY join buffered and
streaming.

## Which builds include them

The POSIX, Windows and Solaris SDK builders apply all four patches. Docker
images and packages that build a vendored SDK consequently ship a patched
engine. Builds linked to an external or distribution-provided `libduckdb` use
that library as provided; the PHP extension build does not patch it. See the
[packaging strategy](../../README.md#libduckdb-strategy) for each package's
source.

The source archive is pinned by hash, and patch application must succeed
cleanly. Installed SDKs carry `source.json`, `nullable-bitpacking.patch`,
`arrow-geometry.patch`, `c-api-copy-functions.patch`,
`array-join-gather.patch`, build metadata and DuckDB's license under
`share/duckdb-sdk/`. Every patch hash enters the SDK
identity, and cache verification requires every patch artifact and its
checksum. Use that metadata to identify our build: the patches do not change
DuckDB's reported `2.0.0` version.

## Nullable bitpacking evidence and patch removal

The [62-case native regression](../../../tests/native/nullable_bitpacking_matrix.c)
checks values and NULL positions after checkpoint and reopen. Its header gives
compile and Memcheck commands. The same workload reports 49 Memcheck errors
with the unpatched 2.0.0 SDK and zero with the patched SDK; the
[minimal reproducer](../../../tests/native/nullable_bitpacking.c) reports one
and zero. SQL assertions alone do not detect undefined bytes in unused NULL
slots. Full-harness Memcheck results are in
[compatibility](../../../docs/compatibility.md#memcheck-and-nullable-integer-persistence).

When upgrading DuckDB, check for an equivalent upstream fix and record its issue
or commit reference here. Run the native regression under Memcheck against the
candidate unpatched engine, then run the full PHP harness. Remove the local
patch and its builder references when that validation confirms the engine no
longer needs it. A patch application failure alone is not evidence of a fix.
