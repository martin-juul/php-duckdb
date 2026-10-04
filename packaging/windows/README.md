# Install on Windows

Check PHP Version, Thread Safety, Compiler and Architecture in `php -i`
before downloading a ZIP. Match your PHP minor version (8.2, 8.3, 8.4 or 8.5),
thread safety (`ts` or `nts`), compiler (`vs16` for PHP 8.2/8.3, `vs17` for
PHP 8.4/8.5) and architecture (`x64`). These packages support 64-bit Windows;
32-bit PHP cannot load them.

1. Extract the ZIP. Copy `php_duckdb.dll` to PHP's extension directory
   (usually the `ext` directory beside `php.exe`).
2. Copy the supplied `duckdb.dll` to the directory containing `php.exe`.
   For another host executable, ensure this directory is on that process's
   `PATH`, or put `duckdb.dll` beside the host executable.
3. Add `extension=php_duckdb.dll` to the active `php.ini`; locate it with
   `php --ini`. Set `extension_dir` to the extension directory if necessary.
4. Restart PHP workers or your web server, then run `php --ri duckdb`.

Both DLLs are required; use the bundled DuckDB 1.5.6 runtime with the extension.
Install the Microsoft Visual C++ Redistributable for Visual Studio 2015–2022
(x64), as required by the official Windows PHP builds: [Microsoft Visual C++
Redistributable](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist)

To test without changing php.ini, run from the directory containing php.exe:

```powershell
.\php.exe -n -d extension_dir=ext -d extension=php_duckdb.dll -r '$c = (new DuckDB\Database())->connect(); var_dump($c->query("SELECT 42 AS x")->fetchRow());'
```

The archive includes `LICENSE.php-duckdb`, `LICENSE.duckdb` and
`build-info.json` describing the exact PHP patch, extension version, DuckDB
version, compiler and source commit used. The `duckdb-sdk` directory records the
engine source digest, both applied patches, build options and artifact hashes.
These records describe the SDK used to produce the bundled DLL.

## Maintainer build

The Packaging workflow builds eight combinations on `windows-2022` with the
SHA-pinned official `php/php-windows-builder` PowerShell module. It calls the
SDK, PHP binary/development pack and Visual Studio setup functions separately,
allowing the checked-out source and DuckDB dependencies to be staged before
`Invoke-Build`. The composite action is deliberately unused because it uploads
a separate automatically generated artifact.

`build.ps1` calls [the SDK builder](../duckdb/build-sdk.ps1) to compile the
source pinned in [source.json](../duckdb/source.json). The builder verifies the
source archive's SHA-256, applies both the nullable-bitpacking and Arrow
conversion/geometry patches with exact context, and builds a Release DLL with
Visual Studio 2022 x64. It includes core functions, Parquet, JSON, ICU and
autocomplete, enables extension autoloading and automatic installation, and
disables host-specific CPU optimization. It preserves upstream's static CRT and
unity build defaults. Set `DUCKDB_DISABLE_UNITY=ON` to disable unity builds when
memory is limited. The installed SDK contains only the C header, DLL, import
library and build provenance; the PHP extension has no C++ client dependency.
PHP 8.2/8.3 still use their matching `vs16` extension toolchain, while PHP
8.4/8.5 use `vs17`.

The Windows builder requires PowerShell, Visual Studio 2022 C++ x64 tools,
CMake, Git and `tar.exe`, all available on the `windows-2022` packaging runner.
It reuses an SDK only when build metadata and every installed artifact hash
match. `DUCKDB_SOURCE_ARCHIVE` can select an existing source archive; the same
digest check applies.

After staging the SDK, `build.ps1` runs applicable PHPTs with the matching PHP
build, including bundled FFI and sockets for Windows notification tests. It
packages the DLLs, licenses and build provenance, extracts the ZIP into a fresh
PHP installation, and checks loading and a query with SDK/dependency
directories removed from `PATH`. PHPT failures and build logs are uploaded
separately and excluded from release assets.

SDK and PHPT worker counts use the shared
[CPU/memory calculation](../resources/README.md), which requires Python 3.
`DUCKDB_BUILD_JOBS` overrides SDK workers and `DUCKDB_JOBS` overrides test
workers. The pinned PHP extension builder controls its own compilation.
