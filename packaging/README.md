# Distribution packaging

Native packages per distribution family. Each directory is a complete,
self-contained packaging recipe; the CI pipeline
(`.github/workflows/packaging.yml`) builds every one of them in a real
distro container, runs the full test suite at build time, then installs
the resulting package and smoke-tests the system PHP loading it through
the distro's own ini mechanism.

## Layout

```
packaging/
  opensuse/       openSUSE Tumbleweed RPM (php8-duckdb)
  fedora/         Fedora RPM (php-pecl-duckdb)
  debian/         Debian sid deb (php-duckdb, system libduckdb-dev)
  debian-trixie/  Debian 13 trixie deb (vendored libduckdb)
  ubuntu/         Ubuntu LTS deb (24.04 noble, 26.04 resolute; vendored libduckdb)
```

All packages install the extension plus an ini drop-in, and all of them
vendor or depend on libduckdb **1.5.5** — the version this extension's
C API usage is pinned to (see `DUCKDB_VERSION` in the spec/rules
files). The pin exists so the package build fails loudly if someone
bumps one place and forgets the other.

## openSUSE Tumbleweed (rpm)

`opensuse/php-duckdb.spec` — single-file spec, no extra sources besides
the two archives rpmbuild expects in `SOURCES/`:

```
php-duckdb-<version>.tar.gz     # the extension source tree
libduckdb-linux-amd64.zip       # from the [DuckDB releases](https://github.com/duckdb/duckdb/releases)
```

Build locally:

```sh
sudo zypper install rpm-build php8 php8-devel gcc gcc-c++ make autoconf chrpath unzip curl

ver=$(awk '/^Version:/ {print $2}' packaging/opensuse/php-duckdb.spec)
dver=$(awk '/^%define duckdb_version/ {print $3}' packaging/opensuse/php-duckdb.spec)
mkdir -p ~/rpmbuild/{SOURCES,SPECS,BUILD,RPMS,SRPMS}
tar --exclude='./.git' --transform "s,^\.,php-duckdb-${ver}," \
  -czf ~/rpmbuild/SOURCES/php-duckdb-${ver}.tar.gz .
curl -fsSL -o ~/rpmbuild/SOURCES/libduckdb-linux-amd64.zip \
  https://github.com/duckdb/duckdb/releases/download/v${dver}/libduckdb-linux-amd64.zip
cp packaging/opensuse/php-duckdb.spec ~/rpmbuild/SPECS/

rpmbuild -ba ~/rpmbuild/SPECS/php-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php8-duckdb-*.rpm
php -m | grep duckdb
```

The spec's `%check` section runs the full `.phpt` suite
(`NO_INTERACTION=1`, `REPORT_EXIT_STATUS=1`); the Swoole/AMPHP/ReactPHP/
True-Async tests skip because those extensions aren't installed. The
vendored libduckdb ships inside the RPM (`%{_libdir}/php8/extensions/
duckdb-lib/libduckdb.so`) with the extension linked to it via an
RPATH — so the package never conflicts with a system libduckdb and
never depends on one appearing later.

## Fedora (rpm)

`fedora/php-pecl-duckdb.spec` — same single-file approach, following
Fedora's PHP extension naming (`php-pecl-*`). Tested on Fedora 44.
Unlike the openSUSE package it does **not** vendor libduckdb: Fedora 42+
ships `duckdb`/`duckdb-devel` in the official repos, so the package
declares `Requires: duckdb` / `BuildRequires: duckdb-devel` and links
against the system library.

```sh
sudo dnf install rpm-build php-devel php-cli gcc gcc-c++ make libtool chrpath unzip curl

ver=$(awk '/^%global upstream_version/ {print $3}' packaging/fedora/php-pecl-duckdb.spec)
dver=$(awk '/^%global duckdb_version/ {print $3}' packaging/fedora/php-pecl-duckdb.spec)
mkdir -p ~/rpmbuild/{SOURCES,SPECS,BUILD,RPMS,SRPMS}
tar --exclude='./.git' --transform "s,^\.,php-duckdb-${ver}," \
  -czf ~/rpmbuild/SOURCES/php-duckdb-${ver}.tar.gz
curl -fsSL -o ~/rpmbuild/SOURCES/libduckdb-linux-amd64.zip \
  https://github.com/duckdb/duckdb/releases/download/v${dver}/libduckdb-linux-amd64.zip
cp packaging/fedora/php-pecl-duckdb.spec ~/rpmbuild/SPECS/

rpmbuild -ba ~/rpmbuild/SPECS/php-pecl-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php-pecl-duckdb-*.rpm
php -m | grep duckdb
```

Fedora specifics: the ini drop-in is `/etc/php.d/40-duckdb.ini`, the
`%{?dist}` release suffix is used, and `%check` runs the same `.phpt`
suite as openSUSE.

## Debian sid (deb)

`debian/` — full debhelper packaging (`dh --with php`), using the
**system** libduckdb: sid ships `libduckdb-dev`, so the package
`Build-Depends` on it and links against `libduckdb.so.1.5` — no
vendored archive.

```sh
sudo apt-get install build-essential debhelper dh-php php-dev libduckdb-dev

cp -r packaging/debian debian
chmod +x debian/rules
dpkg-buildpackage -us -uc -b
sudo dpkg -i ../php-duckdb_*.deb
php -m | grep duckdb
```

The package uses the Debian PHP extension conventions: per-SAPI ini
registration via `/etc/php/8.4/mods-available/duckdb.ini` +
`phpenmod duckdb`, `${php:Depends}` so the package binds to the exact
PHP ABI (`phpapi-*`), and `dh_auto_test` running the `.phpt` suite.

### Debian packaging notes

- Source format is `3.0 (quilt)`; the CI build is binary-only (`-b`),
  so no orig tarball is required.
- `dpkg-parsechangelog` reads the version from `debian/changelog`; a CI
  guard compares it with `PHP_DUCKDB_VERSION` in `php_duckdb.h` so the
  two cannot drift apart.
- The ini drop-in uses debhelper's `dh_php` machinery through
  `debian/php-duckdb.php` (`mod debian/duckdb.ini`).

## Debian 13 trixie (deb) — vendored libduckdb

`debian-trixie/` — same dh-php packaging as sid, but trixie has no
libduckdb package (DuckDB entered Debian after the freeze), so the
prebuilt archive is vendored exactly like the RPMs do. The build stages
it as `duckdb-sdk/{include,lib}` **before** `dpkg-buildpackage`:

```sh
sudo apt-get install build-essential debhelper dh-php php-dev curl unzip

dver=$(awk -F':= *' '/^DUCKDB_VERSION/ {print $2}' packaging/debian-trixie/rules)
arch=$(dpkg --print-architecture)
curl -fsSL -o /tmp/libduckdb.zip \
  https://github.com/duckdb/duckdb/releases/download/v${dver}/libduckdb-linux-${arch}.zip
unzip -o /tmp/libduckdb.zip -d /tmp/libduckdb
mkdir -p duckdb-sdk/include duckdb-sdk/lib
cp /tmp/libduckdb/duckdb.h duckdb-sdk/include/
cp /tmp/libduckdb/libduckdb.so duckdb-sdk/lib/

cp -r packaging/debian-trixie debian
chmod +x debian/rules
dpkg-buildpackage -us -uc -b
sudo dpkg -i ../php-duckdb_*.deb
php -m | grep duckdb
```

The vendored `libduckdb.so` installs into
`/usr/lib/<triplet>/php-duckdb/` and the extension is linked to it via
RUNPATH (`$ORIGIN` relative), so there is no conflict with any future
system libduckdb — but the package still declares
`Conflicts: libduckdb1.5` because the vendored SONAME is unversioned
(`libduckdb.so`) and ldconfig would create a colliding symlink.

## Ubuntu (deb)

Two flavours, depending on whether the release has DuckDB:

- **LTS (24.04 noble, 26.04 resolute) — `ubuntu/`**: no libduckdb
  package exists (DuckDB first entered Ubuntu 26.10), so the prebuilt
  archive is vendored, identical to the trixie variant — see "Debian 13
  (trixie) — vendored libduckdb" above; the staging contract
  (`duckdb-sdk/{include,lib}`, `DUCKDB_VERSION` pin in `debian/rules`)
  is the same.
- **Devel (26.10 stonking and later)**: `libduckdb-dev` is in universe,
  so use the Debian sid packaging (`debian/`) unchanged — the system
  strategy, with `${shlibs:Depends}` picking up `libduckdb1.5`.

All Ubuntu targets use `dh --with php`, `phpenmod` activation and
`${php:Depends}` (`phpapi-*` pinning) exactly like the Debian ones.

### Ubuntu packaging notes

- noble's PHP 8.3.6 headers declare a parameter named `try` in
  `zend_enum.h` — a keyword in C++, renamed upstream to `try_from` in
  the 8.2/8.3 patch series but never backported to noble, which freezes
  the base version. The extension carries `php_duckdb_cxx_compat.h`
  (included in place of `php.h` by every translation unit), which renames
  the keyword away for the duration of the PHP header inclusion on PHP
  < 8.4; nothing distro-specific is needed in the packaging.
- PHP's `make clean` deletes **every** `*.so` in the tree
  (`build/Makefile.global`), including a staged vendored libduckdb.
  Both vendored variants (`debian-trixie/`, `ubuntu/`) stash it across
  `dh_auto_clean` so consecutive builds in the same tree keep working.

## Release assets

Publishing a GitHub Release (from a version tag) triggers the packaging
workflow on the release commit: every target rebuilds from that tag —
full test suite included — and the `release-assets` job attaches all
packages to the release. Asset names carry a distro suffix because the
deb filename is identical across distros:

```
php-duckdb_1.2.0-1_amd64.debian-sid.deb
php-duckdb_1.2.0-1_amd64.debian-13-trixie.deb
php-duckdb_1.2.0-1_amd64.ubuntu-24.04.deb
php-duckdb_1.2.0-1_amd64.ubuntu-26.04.deb
php-duckdb_1.2.0-1_amd64.ubuntu-devel.deb
php8-duckdb-1.2.0-1.x86_64.opensuse-tumbleweed.rpm (+ .src.rpm)
php-pecl-duckdb-1.2.0-1.fc44.x86_64.fedora-44.rpm (+ .src.rpm)
```

dpkg/rpm don't care about the file name, so the suffixed assets install
as usual (`dpkg -i`, `rpm -ivh`). A bare git tag is not enough — the
workflow fires on the `release: published` event, so create the release
from the tag in the GitHub UI (or with `gh release create`).

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
  build environments (`DEB_BUILD_OPTIONS=nocheck`, `%%bcond_without
  tests`).
- Install an ini drop-in through the distro's mechanism, never by
  appending to `php.ini`.
- Declare the PHP ABI dependency (`phpapi-*`, `php(api)`) so a PHP minor
  upgrade forces a rebuild instead of a runtime crash.
