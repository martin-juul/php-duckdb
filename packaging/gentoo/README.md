# Gentoo overlay

This directory is a local Portage repository for `dev-php/php-duckdb`. It uses
Gentoo's [PHP extension eclass](https://devmanual.gentoo.org/eclass-reference/php-ext-source-r3.eclass/index.html)
to build selected PHP 8.2–8.5 slots and enable the extension for their installed
SAPIs. The live ebuild requires a source revision containing the shared SDK
builder and engine patch.

Register the overlay using its absolute checkout path:

```ini
# /etc/portage/repos.conf/php-duckdb.conf
[php-duckdb]
location = /absolute/path/php-duckdb/packaging/gentoo
masters = gentoo
auto-sync = no
```

Choose the PHP slots and allow the live package:

```text
# /etc/portage/package.use/php-duckdb
dev-php/php-duckdb -php_targets_php8-2 -php_targets_php8-3 php_targets_php8-4 -php_targets_php8-5 test
dev-lang/php:8.4 cli ffi sockets

# /etc/portage/package.accept_keywords/php-duckdb
=dev-php/php-duckdb-9999 **
dev-lang/php:8.4 ~amd64
```

```sh
emerge --ask dev-php/php-duckdb
php -r 'printf("extension %s; engine %s\n", phpversion("duckdb"), DuckDB\version());'
```

The ebuild builds the pinned DuckDB source with the shared nullable bitpacking
patch. Portage fetches the engine archive and verifies its Manifest; the SDK
builder additionally verifies [the shared source checksum](../duckdb/source.json).
Compilation does not download another SDK. The source build requires about
20 GB of free build space; engine compilation uses two jobs. PHP extension
compilation follows `MAKEOPTS`.

The patched engine is installed privately as
`/usr/<libdir>/php-duckdb/libphp-duckdb-engine.so`. Each PHP module records that
absolute dependency, and the engine has a distinct SONAME. System DuckDB
libraries are neither replaced nor used. The PHP extension continues using
only DuckDB's C API. License, patch and build provenance are installed under
the package documentation directory.

The official Gentoo `dev-db/duckdb-1.5.5` ebuild currently applies a Thrift
header patch, rather than this bitpacking fix. A future system-library variant
must verify an engine backport before using it; this overlay makes its private
engine explicit.

With `test` enabled, the chosen PHP slots require CLI, FFI and sockets support.
The PHPT invocation sets `DUCKDB_EXTENSION_PATH`, so subprocess tests execute.
Optional Swoole, AMPHP, ReactPHP and TrueAsync tests can still skip when their
runtimes are absent. Cross compilation is not supported by the shared builder.

For a released version containing the SDK builder and patch, copy
`php-duckdb-9999.ebuild` to `php-duckdb-<version>.ebuild`. The same conditional
fetches that release archive. Generate its Manifest with `ebuild <path>
manifest`, run the selected-slot tests, and add architecture keywords only
after validation. Earlier release archives without the patched builder cannot
use this template.

Validation: Bash syntax and pkgcheck 0.10.46 pass against an official Gentoo
repository snapshot. A Gentoo stage3 container loads the overlay metadata and
resolves the PHP 8.4 dependency plan. Its configured binary repository has no
PHP 8.5 package, so a complete Gentoo engine/PHP build and installed-package
smoke test remain unverified.
