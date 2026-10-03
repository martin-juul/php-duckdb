# Oracle Solaris IPS package

This experimental recipe targets Oracle Solaris 11.4 on amd64 with 64-bit
PHP 8.2 or newer. It builds an IPS `.p5p` archive containing the PHP extension
and a private, patched DuckDB library. No native Solaris build, IPS install
or runtime test has been performed here; a successful Linux syntax check
does not establish Solaris support.

illumos is a separate continuation of OpenSolaris, including distributions
such as OpenIndiana and OmniOS. Its toolchains, repositories and PHP packages
need their own recipe and validation. This script rejects illumos and SPARC.
See the [illumos FAQ](https://illumos.github.io/docs/about/faq/).

## Prerequisites

Build on the oldest Oracle Solaris 11.4 release you intend to deploy to.
Use GCC with C++17 support, GNU make, GNU tar and GNU patch, CMake, Python 3,
curl with `--retry-all-errors`, and the IPS developer tools: `pkgrepo`,
`pkgsend`, `pkgmogrify`, `pkgfmt`, `pkgdepend`, `pkglint` and `pkgrecv`, plus
`elfdump` for checking runtime library paths.
Commands `gcc`, `g++`, `gmake`, `gtar` and `gpatch` must be on `PATH`.
The Solaris GNU tool directory can be added with `/usr/gnu/bin`; command
names still need to match the script. GCC 14 and CMake 3.24 are available in
the [Solaris 11.4.81 CBE toolchain](https://blogs.oracle.com/solaris/whats-new-in-the-oracle-solaris-11481-cbe-release).

Install matching PHP CLI, development headers, `phpize` and `php-config`.
The PHP runtime must be managed by IPS so the archive can declare its
dependency. Set `PHP_FMRI` to the installed runtime's package FMRI, including
its version; find it with `pkg list -v`. Match PHP's architecture, thread
safety, debug mode and module API on the target machine. The build checks
the CLI/config version, tests the module and packages `php -i` output for
review. Use a separate package build for each PHP ABI.

Oracle documents [development packages](https://docs.oracle.com/en/operating-systems/solaris/oracle-solaris/11.4/config-app-dev/introduction-setting-up-application-development-environment-oracle-solaris-11.html)
and [IPS package creation](https://docs.oracle.com/cd/E37838_01/html/E61051/pkgcreate.html).
PHP documents the [phpize workflow](https://www.php.net/manual/en/install.pecl.phpize.php).

## Build

From the repository root, with an unused build directory:

```sh
export PATH=/usr/gnu/bin:/usr/bin:/usr/sbin:$PATH
export PHP=/path/to/php PHPIZE=/path/to/phpize PHP_CONFIG=/path/to/php-config
export PHP_FMRI=pkg://your-publisher/your-php-package@your-installed-version
export BUILD_DIR=/var/tmp/php-duckdb-solaris-build
sh packaging/solaris/build.sh
```

The FMRI above is a placeholder; supply the installed PHP package's actual
FMRI. The script creates `BUILD_DIR` and refuses an existing directory. It
builds without installing software, changing PHP configuration or restarting
services. Build products, the generated/resolved IPS manifests, a local
repository and the `.p5p` archive remain there. Redirect the build's output
to a file when a persistent compilation log is needed.

`build-sdk.sh` uses the shared [source pin](../duckdb/source.json) and
[nullable-bitpacking patch](../duckdb/patches/nullable-bitpacking.patch).
It verifies the archive SHA-256 and applies the patch without fuzz. Set
`DUCKDB_SOURCE_ARCHIVE` to a local copy of that exact archive for an offline
engine build. Dependency resolution still needs the installed IPS image
and its publishers. The shared Linux/macOS SDK builder rejects SunOS, so
this directory supplies the Solaris build adapter.

The adapter preserves upstream's SunOS handling and builds core functions,
Parquet, JSON, ICU and autocomplete, with autoloading and automatic
installation enabled. It disables host-specific CPU optimization and keeps
unity builds enabled. Workers are selected from
[available CPU and memory](../resources/README.md). Set `DUCKDB_BUILD_JOBS`
for an explicit engine worker count, `DUCKDB_JOBS` for extension/tests, or
`DUCKDB_DISABLE_UNITY=ON` for limited memory. GCC builds use
`-m64`; override `CC`, `CXX`, `CFLAGS`, `CXXFLAGS` and `LDFLAGS` only with
settings suitable for the target PHP and Solaris ABI. The Solaris adapter
links the engine with `libsocket` and `libnsl`.

DuckDB's pinned CMake source contains a SunOS branch, but that is not a
support guarantee. Its [source-build guide](https://duckdb.org/docs/current/dev/building/overview)
describes platform support. Solaris compilation, all five built-in
extensions and external extension installation still need native testing.
The PHP extension uses only the DuckDB C API.

## Validate and install

The build runs a query smoke test and the repository's unit harness with
the exact built module, including its subprocess tests. Optional runtime
integrations may skip when their dependencies are absent. It resolves ELF
dependencies, runs `pkglint`, publishes to a local repository and exports
the archive. Unresolved dependencies or failing checks stop packaging.

On a clean matching Solaris image, inspect the resolved manifest and
`php-build.txt`, then perform a dry run before installation:

```sh
pkg install -n -g /path/to/php-duckdb-<version>-php8.4-solaris11.4-amd64.p5p \
    library/php-duckdb-8.4
pkg install -g /path/to/php-duckdb-<version>-php8.4-solaris11.4-amd64.p5p \
    library/php-duckdb-8.4
php -n -d extension=/opt/php-duckdb/php-8.4/lib/php/duckdb.so \
    packaging/solaris/smoke.php
```

Replace the archive name and PHP minor version with your build's values.
The build rejects temporary SDK directories in the extension's ELF runtime
search path and requires its installed private library directory first.
The extension has a runtime search path to its private library under
`/opt/php-duckdb/php-<minor>/lib`; it does not replace a system DuckDB.
Enable the same absolute extension path in your PHP configuration after
the smoke test, and validate the intended CLI/FPM/web runtime separately.
The package carries both MIT licenses, the source pin, engine patch, build
metadata and SDK artifact hashes.
IPS uses the `i386` architecture variant for this amd64 package; the native
kernel and PHP checks require 64-bit execution.

Before distributing an archive, run the full harness in the native build
tree where its required tools are available, the nullable-bitpacking C
regression, and installation/uninstallation checks on a clean image.
No Solaris CI job or native runner is included in this recipe.
