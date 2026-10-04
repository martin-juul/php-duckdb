# Patched DuckDB SDK

`build-sdk.sh` builds DuckDB 1.5.6 with the nullable bitpacking patch named in
[source.json](source.json) and the Arrow conversion/geometry patch. It installs
the shared library and `duckdb.h` in the layout consumed by phpize, CMake and
the test harness:

```sh
sh packaging/duckdb/build-sdk.sh \
    --prefix /opt/duckdb --work-dir /tmp/duckdb-build
php tests/harness.php --duckdb-dir=/opt/duckdb
```

See the [patch inventory](patches/README.md) for both defects, local origin,
affected builds, regression evidence and removal criteria. Both engine patches
are part of SDKs built from this checkout. The Arrow patch supplies implicit
conversion transactions and preserves geometry CRS metadata on import.

The builder needs Python 3, curl, tar, patch, CMake, make and C/C++ compilers.
The source archive is pinned by SHA-256, and both patches apply without fuzz.
Set `DUCKDB_SOURCE_ARCHIVE` to use an already downloaded archive; the same hash
check applies. `DUCKDB_SDK_PREFIX`, `DUCKDB_BUILD_DIR` and `DUCKDB_BUILD_JOBS`
provide defaults for the command-line options. Without a worker override,
[CPU and memory availability](../resources/README.md) determine parallelism.

The Release build includes core functions, Parquet, JSON, ICU and autocomplete,
enables extension autoloading and automatic installation, and disables native
CPU optimization, shell and unit-test binaries. Set `DUCKDB_DISABLE_UNITY=ON`
for low-memory hosts; the default uses upstream unity builds. The PHP extension
continues to use the C API; the installed SDK contains no C++ client headers.

On macOS, `MACOSX_DEPLOYMENT_TARGET` defaults to `11.0`.
`DUCKDB_OSX_ARCHITECTURES` defaults to the host architecture; set it to
`'x86_64;arm64'` for a universal library. Linux release builds must use a
compiler and system-library baseline compatible with every target package;
building on a newer distribution does not ensure that compatibility.

The builder reuses an SDK only when its source, both patches, builder, platform,
compiler and build options match, and installed artifact hashes still verify.
Build metadata, source pins, both patches and DuckDB's MIT license are installed
under `share/duckdb-sdk/`. Keep that attribution with packaged libraries.
Use a separate work directory for concurrent builds.

## CI and local caches

The CI action saves the installed SDK as soon as the engine build succeeds,
before PHP builds and tests run. Its cache key uses the builder's exact
fingerprint, including the source, both patches, compiler, system libraries and
build options. Changing PHP code, PHP versions, worker counts or the Linux
kernel does not by itself require another engine build. A compiler, either patch
or SDK build option change does.

Packaging jobs keep SDK artifacts outside the package source tree, so RPM
source extraction and Debian cleaning do not remove them. To use the same
mechanism locally, set `DUCKDB_SDK_CACHE_DIR` to a persistent directory before
running either SDK builder. Each entry contains the installed header, library
and provenance files, with its build fingerprint as the directory name. The
builder checks metadata and every artifact hash before copying an entry into
the requested prefix; missing or damaged entries cause a rebuild. No compiler
objects or source trees are cached. Remove old entries when you no longer need
their toolchains or build options.

Docker builds produce one SDK per architecture and pass it to all PHP image
jobs as a workflow artifact. A compact BuildKit cache preserves that SDK between
runs. A cold run therefore compiles two engines, rather than one for each of
the twelve PHP image jobs. Each image still builds and tests its PHP extension.

GitHub caches follow branch access rules: pull requests can restore the default
branch's caches, while a cache created by one pull request is available only to
that pull request. The first run with new engine inputs still needs a build. See
[GitHub's cache access
rules](https://docs.github.com/en/actions/using-workflows/caching-dependencies-to-speed-up-workflows#restrictions-for-accessing-a-cache).

Packaging caches are saved after the package build step, including when later
extension tests in that step fail. Cancelling the job before this save can still
lose a newly built SDK. Main CI and Docker save the SDK before starting PHP
builds. Distribution-provided DuckDB packages do not use these caches.

Vendored shipping recipes use this builder and carry both engine patches in
their libraries. Each packaging job still needs to validate its platform before
release. Distribution-provided libraries require equivalent fixes or their own
backports; this builder does not modify them.
