# Distribution packaging

Native packages for Linux distributions live here, one directory per
distribution family:

```
packaging/
  opensuse/
    php-duckdb.spec        # RPM spec — openSUSE Tumbleweed/Leap, SLE
  fedora/
    php-pecl-duckdb.spec   # RPM spec — Fedora 43+, EPEL-compatible
  debian/
    control, rules, ...    # debhelper packaging — Debian sid/forky, Ubuntu
```

The extension itself is distribution-agnostic (phpize + `--with-duckdb`);
what differs per distro is package naming, the PHP dev package, the ini
drop-in directory, and how libduckdb is provided.

## libduckdb strategy

The extension links against the DuckDB C API library (`libduckdb`), which
most distributions do **not** package yet. Each packaging target chooses
one of:

1. **System package** (preferred when available) — e.g. Debian sid ships
   [`libduckdb-dev`](https://packages.debian.org/sid/libdevel/libduckdb-dev),
   Arch Linux ships [`duckdb`](https://archlinux.org/packages/extra/x86_64/duckdb/)
   in Extra.
2. **Vendored prebuilt archive** — download `libduckdb-linux-<arch>.zip`
   from the [DuckDB releases](https://github.com/duckdb/duckdb/releases)
   and ship `libduckdb.so` inside the package. This is what the openSUSE
   and Fedora specs and the project's Docker images do.

Pin the vendored archive to the DuckDB version the extension is tested
against (see `DUCKDB_VERSION` in the Dockerfile) and keep the version in
sync across packaging targets.

## openSUSE (RPM)

`opensuse/php-duckdb.spec` builds `php8-duckdb` for Tumbleweed, Leap 16
and SLE 16 — anywhere `php8-devel >= 8.2` exists. It vendors the prebuilt
libduckdb archive and is validated end to end in CI (build + `%check`
test suite + install smoke test on Tumbleweed).

### Local build

```bash
sudo zypper install rpm-build php8-devel gcc-c++ make autoconf chrpath unzip

# Stage the two sources rpmbuild expects:
mkdir -p ~/rpmbuild/{SOURCES,SPECS}
git archive --prefix=php-duckdb-1.2.0/ -o ~/rpmbuild/SOURCES/php-duckdb-1.2.0.tar.gz HEAD
curl -L -o ~/rpmbuild/SOURCES/libduckdb-linux-amd64.zip \
  https://github.com/duckdb/duckdb/releases/download/v1.5.5/libduckdb-linux-amd64.zip

rpmbuild -ba packaging/opensuse/php-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php8-duckdb-*.rpm
php -m | grep duckdb
```

Build with `--without tests` to skip the `%check` test suite.

### OBS (Open Build Service)

The spec follows `server:php:extensions` conventions (php8 macros from
`php8-devel`, `php(api)`/`php(zend-abi)` ABI pinning), so it can be
dropped into an OBS home project as-is. Upload the spec plus the two
sources (`osc add`); OBS builds both `x86_64` and `aarch64` thanks to the
per-arch `Source1` conditionals. When DuckDB lands in Factory, replace
the vendored archive with `BuildRequires: duckdb-devel` and a runtime
`Requires: libduckdb`.

## Fedora (RPM)

`fedora/php-pecl-duckdb.spec` builds `php-pecl-duckdb` on Fedora 43+
(anything with `php-devel >= 8.2`). It follows the Fedora PECL packaging
conventions: `php-pecl-*` naming with the `php-duckdb` /
`php-pecl(DuckDB)` / `php-pie(martinjuul/duckdb)` provides set, ini
drop-in at `/etc/php.d/40-duckdb.ini`, `%{?dist}` release suffix, and a
`%prep` guard that fails the build if the spec version and
`PHP_DUCKDB_VERSION` drift apart.

### Local build

```bash
sudo dnf install rpm-build php-devel php-cli gcc-c++ make libtool chrpath unzip

mkdir -p ~/rpmbuild/{SOURCES,SPECS}
git archive --prefix=php-duckdb-1.2.0/ -o ~/rpmbuild/SOURCES/php-duckdb-1.2.0.tar.gz HEAD
curl -L -o ~/rpmbuild/SOURCES/libduckdb-linux-amd64.zip \
  https://github.com/duckdb/duckdb/releases/download/v1.5.5/libduckdb-linux-amd64.zip

rpmbuild -ba packaging/fedora/php-pecl-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php-pecl-duckdb-*.rpm
php -m | grep duckdb
```

Build with `--without tests` to skip the `%check` test suite.

### Fedora packaging notes

- Fedora's `check-rpaths` buildroot policy **errors** on the build-tree
  runpath that PHP's build system (`PHP_ADD_LIBRARY_WITH_PATH`) bakes
  into the extension for `--with-duckdb=DIR`. The spec strips it with
  `chrpath -d` after install; libduckdb resolves via ldconfig from
  `%{_libdir}`. (openSUSE does not fail on this, but its spec strips the
  runpath too — a dangling build-tree path is a packaging defect either
  way.)
- In spec `%install`, the `:`-style pseudo-comments must not contain
  unquoted parentheses — they are parsed as subshell syntax and abort the
  section with "syntax error near unexpected token `('".

## Debian (deb)

`debian/` builds `php-duckdb` on Debian sid/forky (and Ubuntu derivatives
with a `libduckdb-dev` package). This is the one target that can use the
**system libduckdb strategy**: sid and forky ship
[`libduckdb-dev`](https://packages.debian.org/sid/libdevel/libduckdb-dev)
1.5.5, so no vendored archive is involved — the package build-depends on
`libduckdb-dev` and picks up a versioned runtime dependency on
`libduckdb1.5` via `${shlibs:Depends}` automatically.

The packaging uses `dh --with php` (`dh-php`): the ini drop-in is
registered through `debian/php-duckdb.php` into
`/etc/php/<version>/mods-available/`, activated for all SAPIs by
`phpenmod` in the maintainer scripts, and `${php:Depends}` pins the
package to the exact PHP API (`phpapi-*`) it was built against.

### Local build

```bash
sudo apt-get install build-essential debhelper dh-php php-dev php-cli libduckdb-dev

# dpkg insists on ./debian at the source root; copy it out of packaging/:
cp -r packaging/debian debian
chmod +x debian/rules

dpkg-buildpackage -us -uc -b
sudo dpkg -i ../php-duckdb_*.deb
php -m | grep duckdb
```

The test suite runs in `dh_auto_test` with `NO_INTERACTION=1` /
`REPORT_EXIT_STATUS=1`; skip it with `DEB_BUILD_OPTIONS=nocheck`.

### Debian packaging notes

- `debian/rules` needs the execute bit; git checkouts keep it, but a
  plain copy may not — `chmod +x debian/rules` before building.
- `PHP_RPATH=no` is exported in `debian/rules`. PHP's `build/php.m4`
  honours it by emptying `ld_runpath_switch`, so unlike the RPM specs no
  `chrpath -d` fix-up is needed: the built `duckdb.so` carries no RPATH
  at all and resolves `libduckdb.so.1.5` via ldconfig.
- The install step must pass `INSTALL_ROOT`, not `DESTDIR` — PHP's
  `Makefile.global` only honours the former.
- The `unresolvable reference to symbol add_assoc_*` warnings from
  `dpkg-shlibdeps` are expected: PHP extension symbols resolve against
  the PHP binary at runtime, not against a linked library.
- Source format is `3.0 (quilt)`; the CI build is binary-only (`-b`),
  which needs no orig tarball. To build a source package, place
  `php-duckdb_<version>.orig.tar.gz` next to the tree first.

## Adding another distribution

Copy the closest existing target and adjust the distro-specific knobs:

| Knob | openSUSE example | What to check elsewhere |
|---|---|---|
| Package name | `php8-duckdb` | Debian: `php-duckdb`, Fedora: `php-pecl-duckdb`, Alpine: `php8X-duckdb`, Arch: `php-duckdb` |
| PHP dev package | `php8-devel` | Debian: `php-dev`, Fedora: `php-devel`, Alpine: `php8X-dev` |
| Extension dir | `%{php_extdir}` macro | `php-config --extension-dir` works everywhere as fallback |
| Ini drop-in | `/etc/php8/conf.d/*.ini` | Debian: per-SAPI `conf.d` + `phpenmod`, Fedora: `/etc/php.d`, Alpine: `/etc/php8X/conf.d` |
| ABI runtime deps | `php(api)`/`php(zend-abi)` provides | Debian uses `phpapi-*` virtual packages |
| libduckdb | vendored zip | prefer a system package when the distro has one |
| libc | glibc | Alpine (musl): DuckDB ships no official musl binaries and musl builds are notably slower — build libduckdb from source there |

Non-negotiables whatever the distro:

- Run the test suite at build time (`make test` with `NO_INTERACTION=1`
  and `REPORT_EXIT_STATUS=1`), with an opt-out switch for restricted
  build environments.
- Smoke-test the *installed* package: load via the distro ini mechanism
  and run a query.
- Ship `LICENSE` and declare the ABI dependency on PHP, so a PHP minor
  upgrade forces a rebuild instead of a runtime crash.
