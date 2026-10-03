# DuckDB engine patches

This repository carries one DuckDB source patch. SDK builds apply it before
compiling `libduckdb`; it is also included in the libraries shipped by those
builds. The PHP extension still links through DuckDB's public C API.

## Patch inventory

| Patch | Source baseline | Purpose | Origin and upstream tracking |
| --- | --- | --- | --- |
| [nullable-bitpacking.patch](nullable-bitpacking.patch) | DuckDB 1.5.6, pinned in [source.json](../source.json) | Initialize NULL slots before frame-of-reference bitpacking | Local fix developed for this repository; no upstream issue, PR or merge reference is recorded here |

The patch is maintained against this exact source baseline. Its presence does
not establish whether another DuckDB release contains the same defect or an
equivalent fix. The general PHP extension support for DuckDB 1.5.5 does not
mean this patch has been validated against 1.5.5.

## Why the patch exists

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

## Which builds include it

The POSIX, Windows and Solaris SDK builders apply the patch. Docker images and
packages that build a vendored SDK consequently ship a patched engine. Builds
linked to an external or distribution-provided `libduckdb` use that library as
provided; the PHP extension build does not patch it. See the
[packaging strategy](../../README.md#libduckdb-strategy) for each package's source.

The source archive is pinned by hash, and patch application must succeed
cleanly. Installed SDKs carry `source.json`, `nullable-bitpacking.patch`, build
metadata and DuckDB's license under `share/duckdb-sdk/`. Use that metadata to
identify our build: the patch does not change DuckDB's reported `1.5.6` version.

## Evidence and removal criteria

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
