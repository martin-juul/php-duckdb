# Patched DuckDB SDK

`build-sdk.sh` builds DuckDB 1.5.6 with the nullable bitpacking patch named in
[source.json](source.json). It installs the shared library and `duckdb.h` in
the layout consumed by phpize, CMake and the test harness:

```sh
sh packaging/duckdb/build-sdk.sh \
    --prefix /opt/duckdb --work-dir /tmp/duckdb-build
php tests/harness.php --duckdb-dir=/opt/duckdb
```

The builder needs Python 3, curl, tar, patch, CMake, make and C/C++ compilers.
The source archive is pinned by SHA-256, and the patch applies without fuzz.
Set `DUCKDB_SOURCE_ARCHIVE` to use an already downloaded archive; the same hash
check applies. `DUCKDB_SDK_PREFIX`, `DUCKDB_BUILD_DIR` and `DUCKDB_BUILD_JOBS`
provide defaults for the command-line options. Without a worker override,
[CPU and memory availability](../resources/README.md) determine parallelism.

The Release build includes core functions, Parquet, JSON, ICU and autocomplete,
enables extension autoloading and automatic installation, and disables native CPU
optimization, shell and unit-test binaries. Set `DUCKDB_DISABLE_UNITY=ON` for
low-memory hosts; the default uses upstream unity builds. The PHP extension
continues to use the C API; the installed SDK contains no C++ client headers.

On macOS, `MACOSX_DEPLOYMENT_TARGET` defaults to `11.0`.
`DUCKDB_OSX_ARCHITECTURES` defaults to the host architecture; set it to
`'x86_64;arm64'` for a universal library. Linux release builds must use a
compiler and system-library baseline compatible with every target package;
building on a newer distribution does not ensure that compatibility.

The builder reuses an SDK only when its source, patch, builder, platform,
compiler and build options match, and installed artifact hashes still verify.
Build metadata, source pins, the patch and DuckDB's MIT license are installed
under `share/duckdb-sdk/`. Keep that attribution with packaged libraries.
Use a separate work directory for concurrent builds.

Vendored shipping recipes use this builder and carry the engine patch in
their libraries. Each packaging job still needs to validate its platform
before release. Distribution-provided libraries require their own backport;
this builder does not modify them.
