# Install on Windows

Download the ZIP matching your PHP minor version (8.2, 8.3, 8.4 or 8.5),
thread safety (`ts` or `nts`), compiler (`vs16` for PHP 8.2/8.3, `vs17` for
PHP 8.4/8.5) and architecture (`x64`). Check `php -i` for PHP Version,
Thread Safety, Compiler and Architecture. These packages support 64-bit
Windows; 32-bit PHP cannot load them.

1. Extract the ZIP. Copy `php_duckdb.dll` to PHP's extension directory
   (usually the `ext` directory beside `php.exe`).
2. Copy the supplied `duckdb.dll` to the directory containing `php.exe`.
   For another host executable, ensure this directory is on that process's
   `PATH`, or put `duckdb.dll` beside the host executable.
3. Add `extension=php_duckdb.dll` to the active `php.ini`; locate it with
   `php --ini`. Set `extension_dir` to the extension directory if necessary.
4. Restart PHP workers or your web server, then run `php --ri duckdb`.

Use the bundled DuckDB 1.5.6 runtime with the extension. Both DLLs are
required. Install the Microsoft Visual C++ Redistributable for Visual
Studio 2015–2022 (x64), as required by the official Windows PHP builds:
https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist

To test without changing php.ini, run from the directory containing php.exe:

```powershell
.\php.exe -n -d extension_dir=ext -d extension=php_duckdb.dll -r '$c = (new DuckDB\Database())->connect(); var_dump($c->query("SELECT 42 AS x")->fetchRow());'
```

The archive includes `LICENSE.php-duckdb`, `LICENSE.duckdb` and
`build-info.json` describing the exact PHP patch, extension version,
DuckDB version, compiler and source commit used.

# Maintainer build

The Packaging workflow builds eight combinations on `windows-2022` using
the SHA-pinned official `php/php-windows-builder` PowerShell module. Its
SDK, PHP binary/development pack and Visual Studio setup functions are
called separately so the checked-out source and DuckDB dependencies can be
staged before `Invoke-Build`. The composite action is deliberately unused
because it uploads a separate automatically generated artifact.

`build.ps1` verifies DuckDB's official archive digest, runs all applicable
PHPTs using the matching PHP build (including bundled FFI and sockets for
Windows notification tests), packages the DLLs and licenses, then extracts
the ZIP into a fresh PHP installation and checks loading and a query with
SDK/dependency directories removed from PATH. PHPT failures and build logs
are uploaded separately and excluded from release assets.
