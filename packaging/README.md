# Distribution packaging

Distribution recipes live here, one directory per platform or distribution
family:

```text
packaging/
  opensuse/
    php-duckdb.spec        # RPM spec — openSUSE Tumbleweed/Leap, SLE
  fedora/
    php-pecl-duckdb.spec   # RPM spec — Fedora 43+, EPEL-compatible
  almalinux/
    php-pecl-duckdb.spec   # RPM spec — AlmaLinux 9/10, Remi PHP 8.2–8.5
  amazonlinux/
    php-pecl-duckdb.spec   # RPM spec — Amazon Linux 2023 and 2027 preview
  gentoo/
    dev-php/php-duckdb/    # local overlay — live ebuild, private patched engine
  solaris/
    build.sh, package.mog # experimental Oracle Solaris 11.4 amd64 IPS archive
  macos/
    build.sh, install.sh   # tarball — macOS 12+, Intel (incl. pre-AVX2) and Apple Silicon
  windows/
    build.ps1, README.md   # ZIP — x64, PHP 8.2–8.5, TS and NTS
  debian/
    control, rules, ...    # debhelper — Debian sid/forky, Ubuntu devel (patched SDK)
  debian-trixie/
    control, rules, ...    # debhelper — Debian 13 trixie (vendored libduckdb)
  ubuntu/
    control, rules, ...    # debhelper — Ubuntu LTS 24.04/26.04 (vendored libduckdb)
```

The extension builds with phpize and `--with-duckdb` across distributions.
Packaging targets supply the distribution-specific package names, PHP dev
packages, ini drop-in directories, and libduckdb sources.

PIE distributes the PHP extension as `martinjuul/duckdb`. RPM names and
provides such as `php-pecl-duckdb` and `php-pecl(DuckDB)` retain the existing
distribution packaging conventions; those names do not imply publication on
PECL.

Build scripts select parallel workers from
[available CPU and memory](resources/README.md), unless explicitly overridden.

## libduckdb strategy

All packaging targets build and ship this repository's pinned, patched DuckDB
SDK through [the shared SDK builders](duckdb/README.md). They do not depend on
a distribution DuckDB runtime or development package. The source version,
commit and archive hash are shared in [source.json](duckdb/source.json).

Both [engine patches](duckdb/patches/README.md) are built into the shipped
library. The nullable bitpacking patch initializes unused NULL slots; the
Arrow patch supplies conversion transactions and preserves declared geometry
CRS metadata. Patches, source pins, build metadata and DuckDB's license
accompany the SDK under `share/duckdb-sdk/`. Cache verification requires both
patch artifacts and their checksums. Existing release assets are not replaced;
the release workflow builds from its release commit.

Source builds outside these packaging recipes can still link to an external
SDK. Those libraries do not receive this patch set from compiling the PHP
extension and require separate validation.

## Gentoo (Portage overlay)

The [Gentoo overlay](gentoo/README.md) provides a live `dev-php/php-duckdb`
ebuild using Gentoo's PHP extension eclass. It builds the pinned, patched
engine and installs it privately, with tests for each selected PHP slot.
The live ebuild follows the default branch, which includes the SDK builder
and patch. Released extension archives through 1.3.1 do not include the SDK
builder; see the overlay instructions before creating a versioned ebuild.

## Oracle Solaris (experimental IPS recipe)

The [Solaris recipe](solaris/README.md) builds an IPS `.p5p` archive for
Oracle Solaris 11.4 amd64 with PHP 8.2 or newer. It uses the shared engine
source pin and patch, tests the extension and packages a private library.
Native Solaris builds and IPS installation remain unverified. The recipe
does not cover illumos or SPARC.

## openSUSE (RPM)

`opensuse/php-duckdb.spec` builds `php8-duckdb` wherever
`php8-devel >= 8.2` exists, including Tumbleweed, Leap 16 and SLE 16. It
builds and vendors the patched source SDK. CI validates the complete build,
`%check` test suite and installed-package smoke test on Tumbleweed.

### Local build

```bash
sudo zypper install rpm-build php8-devel gcc-c++ make autoconf chrpath cmake python3 patch curl

# Stage the two sources rpmbuild expects:
mkdir -p ~/rpmbuild/{SOURCES,SPECS}
git archive --prefix=php-duckdb-1.3.1/ -o ~/rpmbuild/SOURCES/php-duckdb-1.3.1.tar.gz HEAD
ver=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["version"])')
url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
curl -fL -o "$HOME/rpmbuild/SOURCES/duckdb-${ver}.tar.gz" "$url"

rpmbuild -ba packaging/opensuse/php-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php8-duckdb-*.rpm
php -m | grep duckdb
```

Build with `--without tests` to skip the `%check` test suite.

### OBS (Open Build Service)

The spec follows `server:php:extensions` conventions: php8 macros from
`php8-devel` and ABI pinning through `php(api)`/`php(zend-abi)`. It can
therefore be used unchanged in an OBS home project. Upload the spec and the
two source archives with `osc add`; the same pinned DuckDB source builds on
both `x86_64` and `aarch64`. The package retains the shared patched SDK
regardless of distribution DuckDB availability.

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
sudo dnf install rpm-build php-devel php-cli gcc-c++ make libtool chrpath cmake python3 patch curl

mkdir -p ~/rpmbuild/{SOURCES,SPECS}
git archive --prefix=php-duckdb-1.3.1/ -o ~/rpmbuild/SOURCES/php-duckdb-1.3.1.tar.gz HEAD
ver=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["version"])')
url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
curl -fL -o "$HOME/rpmbuild/SOURCES/duckdb-${ver}.tar.gz" "$url"

rpmbuild -ba packaging/fedora/php-pecl-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php-pecl-duckdb-*.rpm
php -m | grep duckdb
```

Build with `--without tests` to skip the `%check` test suite.

### Fedora packaging notes

- PHP's build system (`PHP_ADD_LIBRARY_WITH_PATH`) adds a build-tree
  runpath when using `--with-duckdb=DIR`. Fedora's `check-rpaths`
  buildroot policy **errors** on that path, so the spec strips it with
  `chrpath -d` after install. libduckdb then resolves via ldconfig from
  `%{_libdir}`. openSUSE does not fail on this path, but its spec strips
  it too; a dangling build-tree path is a packaging defect on either
  distribution.
- In spec `%install`, the `:`-style pseudo-comments must not contain
  unquoted parentheses — they are parsed as subshell syntax and abort the
  section with "syntax error near unexpected token `('".

## AlmaLinux (RPM — Remi PHP)

`almalinux/php-pecl-duckdb.spec` builds `php-pecl-duckdb` on AlmaLinux
9 and 10 using PHP from the [Remi repository](https://rpms.remirepo.net/).
The distribution's own PHP is too old for the required >= 8.2 version;
Remi is the standard route to current PHP on the RHEL family.

The target is Remi's *default-namespace* PHP: the `php:remi-8.x` module
streams from `remi-modular`, **not** the `phpXX-php-*` SCL packages.
Current `remi-release` no longer ships the legacy dedicated
`remi-php82/83/84/85` repos. Module streams are Remi's documented flow
on both EL9 and EL10.

The spec follows Fedora/Remi extension conventions: `php-pecl-*` naming,
`/etc/php.d/40-duckdb.ini`, a `%{?dist}` release suffix and the `%prep`
version-drift guard. Its `php(api)`/`php(zend-abi)` Requires pin the
package to the exact PHP ABI used to build it. An RPM built against Remi
8.4 can therefore install on any other PHP 8.4 build providing that ABI,
such as AppStream php 8.4 where available. The bundled SDK keeps the engine
source and both patches consistent across distribution targets.

CI builds every combination of AlmaLinux 9/10 × Remi PHP
8.2/8.3/8.4/8.5 × amd64/arm64.

### Install

```bash
sudo dnf install epel-release
sudo dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset -y php          # drops any AppStream php stream
sudo dnf module install php:remi-8.4/common
sudo rpm -ivh php-pecl-duckdb-1.3.1-1.el9.x86_64.rpm
php -m | grep duckdb
```

### Local build

```bash
sudo dnf install epel-release
sudo dnf install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
sudo dnf module reset -y php
sudo dnf module install php:remi-8.4/common
sudo dnf install \
  php-devel php-cli rpm-build gcc-c++ make libtool chrpath cmake python3 patch curl

mkdir -p ~/rpmbuild/{SOURCES,SPECS}
git archive --prefix=php-duckdb-1.3.1/ -o ~/rpmbuild/SOURCES/php-duckdb-1.3.1.tar.gz HEAD
ver=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["version"])')
url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
curl -fL -o "$HOME/rpmbuild/SOURCES/duckdb-${ver}.tar.gz" "$url"

rpmbuild -ba packaging/almalinux/php-pecl-duckdb.spec
sudo rpm -ivh ~/rpmbuild/RPMS/x86_64/php-pecl-duckdb-*.rpm
php -m | grep duckdb
```

Build with `--without tests` to skip the `%check` test suite.

## Amazon Linux (RPM)

[The Amazon Linux recipe](amazonlinux/README.md) builds `php-pecl-duckdb`
against namespaced distribution PHP packages. CI configures Amazon Linux
2023 with PHP 8.2–8.5 and Amazon Linux 2027 with PHP 8.5, on amd64 and arm64.
The 2027 target is a
[public evaluation preview](https://docs.aws.amazon.com/linux/al2027/ug/container-base.html),
using its official `public.ecr.aws/amazonlinux/amazonlinux:2027` image and
[PHP 8.5 runtime](https://docs.aws.amazon.com/linux/al2027/ug/language-runtimes-php.html).
It is not a production support claim.

The RPM uses the shared pinned source SDK, including both engine patches,
and packages a private engine with its own SONAME. The PHP module has a
RUNPATH to that engine, so a distribution DuckDB package can coexist with it.
Builds stage both source archives, run the harness, install the resulting RPM
and check that the system PHP loads the private engine. See the recipe for
local build commands, runtime selection and current native validation status.

## macOS (tarball)

Homebrew only ships its own formulae, and macOS has no distribution package
manager with a PHP extension channel available to this project. The `macos/`
target therefore ships a **tarball** per PHP minor version and architecture:

```text
php-duckdb-1.3.1-php8.4-macos12-x86_64.tar.gz
```

Each tarball contains `duckdb.so`, the vendored `libduckdb.dylib` and
`install.sh`. Both archives target **macOS 12+** and run natively on Intel
and Apple Silicon. The two builds have the following constraints:

- Both the engine and extension target macOS 12+ with native CPU
  optimization disabled. There is no separate AVX2 build; the Intel package
  retains the baseline CPU target. The SDK builder can also produce a
  universal library when `DUCKDB_OSX_ARCHITECTURES='x86_64;arm64'` is set.
- `duckdb.so` references the dylib as `@rpath/libduckdb.dylib` with an
  `@loader_path` rpath, so the pair works from any directory as long as
  they sit side by side (install.sh puts both in PHP's extension dir).
- The tarball's PHP minor must match the target PHP exactly (Zend ABI).
  CI builds PHP 8.2/8.3/8.4/8.5 on both architectures (8 tarballs).

### Install

```bash
tar -xzf php-duckdb-1.3.1-php8.4-macos12-x86_64.tar.gz
sh php-duckdb-1.3.1-php8.4-macos12-x86_64/install.sh
php -m | grep duckdb
```

Browser downloads may cause macOS to quarantine the binaries. Run
`xattr -d com.apple.quarantine` on the extracted files to clear it;
curl downloads are not quarantined.

### Local build

Requires a Homebrew PHP (`brew install php` or the shivammathur/php tap)
with `phpize` on PATH, plus CMake, Python 3 and the Xcode command-line tools:

```bash
brew install cmake python
sh packaging/macos/build.sh   # builds patched libduckdb, then the extension and tests
```

The build defaults to `MACOSX_DEPLOYMENT_TARGET=12.0` and reads the shared
DuckDB source pin from `duckdb/source.json`. The tarball includes DuckDB
license and build metadata alongside the binaries.

## Debian (deb)

`debian/` builds `php-duckdb` on Debian sid/forky and Ubuntu devel with the
shared patched SDK. Stage the pinned engine archive before building; there is
no `libduckdb-dev` build dependency or system DuckDB runtime dependency.

The recipe installs the engine privately at
`/usr/lib/<multiarch>/php-duckdb/libphp-duckdb-engine.so`. The engine has a
separate SONAME, and the PHP module records that dependency with a RUNPATH to
its private directory. Distribution DuckDB packages can coexist with it;
the package does not replace or conflict with them.

The packaging uses `dh --with php` (`dh-php`): the ini drop-in is
registered through `debian/php-duckdb.php` into
`/etc/php/<version>/mods-available/`, activated for all SAPIs by
`phpenmod` in the maintainer scripts, and `${php:Depends}` pins the
package to the exact PHP API (`phpapi-*`) it was built against.

For Debian 13 (trixie), which has no `libduckdb` package at all, use the
`debian-trixie/` variant described further below instead.

### Local build

```bash
sudo apt-get install build-essential debhelper dh-php php-dev php-cli curl cmake python3 patch patchelf

url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
curl -fL -o duckdb-source.tar.gz "$url"

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
- `debian/rules` removes the temporary build SDK path and uses `patchelf`
  to set the private engine SONAME, module dependency and installed RUNPATH.
- The install step must pass `INSTALL_ROOT`, not `DESTDIR` — PHP's
  `Makefile.global` only honours the former.
- The `unresolvable reference to symbol add_assoc_*` warnings from
  `dpkg-shlibdeps` are expected: PHP extension symbols resolve against
  the PHP binary at runtime, not against a linked library.
- Source format is `3.0 (quilt)`; the CI build is binary-only (`-b`),
  which needs no orig tarball. To build a source package, place
  `php-duckdb_<version>.orig.tar.gz` next to the tree first.

### Debian 13 (trixie) — vendored libduckdb

DuckDB entered Debian after the trixie freeze, so stable has no
`libduckdb-dev`. The `debian-trixie/` target builds and vendors the patched SDK,
just as the other packaging targets do. This recipe currently installs the
vendored runtime at the distribution library path:

- Stage the pinned source archive as `duckdb-source.tar.gz` before the
  build. `debian/rules` builds the patched SDK offline with the distribution's
  compiler flags; its source pins come from `duckdb/source.json`.
- The source-built library's SONAME is the *unversioned* `libduckdb.so`
  (Debian's own build versions it `libduckdb.so.1.5`), so the package
  ships `/usr/lib/<multiarch>/libduckdb.so` and declares
  `Conflicts: libduckdb1.5` to keep that global library path exclusive.
- `Architecture: amd64 arm64` — the native build architectures covered by CI.
- The vendored lib is not on the loader path until the package is
  installed. The build-time test suite therefore sets `LD_LIBRARY_PATH`
  to `duckdb-sdk/lib`, as the RPM `%check` does.

Local build:

```bash
sudo apt-get install build-essential debhelper dh-php php-dev php-cli curl cmake python3 patch

url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
curl -fL -o duckdb-source.tar.gz "$url"

cp -r packaging/debian-trixie debian
chmod +x debian/rules
dpkg-buildpackage -us -uc -b
sudo dpkg -i ../php-duckdb_*.deb
php -m | grep duckdb
```

## Ubuntu (deb)

All Ubuntu targets build the shared patched SDK. Choose the recipe for the
release:

- **LTS (24.04 noble, 26.04 resolute) — `ubuntu/`**: no libduckdb
  package exists (DuckDB first entered Ubuntu 26.10), so the patched SDK
  is built and vendored, identical to the trixie variant. Stage the same
  `duckdb-source.tar.gz` archive before building.
- **Devel (26.10 stonking and later) — `debian/`**: use the Debian sid
  recipe and its private patched engine. Stage `duckdb-source.tar.gz` before
  building, just as for the LTS recipes.

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
  The vendored recipes (`debian/`, `debian-trixie/`, `ubuntu/`) preserve that
  library across `dh_auto_clean`, allowing consecutive builds in the
  same tree.

## Publishing to Packagist for PIE

1. Sign in to [Packagist](https://packagist.org/) as `martin-juul` and
   submit `https://github.com/martin-juul/php-duckdb`. The package name in
   `composer.json` is `martinjuul/duckdb`; the vendor prefix does not need
   to match the account name.
2. Enable Packagist automatic updates through its GitHub integration or
   repository webhook, and verify the package's update status.
3. Validate `composer.json` and pass the PIE installation CI check before
   releasing. Create and push a new version tag containing the top-level
   `php-ext` metadata and the value-taking `with-duckdb` configure option.
   Existing tags still contain their original metadata; publish a new
   version rather than moving an existing tag.
4. Verify Packagist discovers the tagged version, then test it with
   `pie install martinjuul/duckdb --with-duckdb=/opt/duckdb` against a
   separately installed DuckDB C library.

See the [PIE maintainer guide](https://php.github.io/pie/#docs/extension-maintainers)
for extension metadata requirements. Publishing a GitHub Release from the
same tag additionally builds the distribution assets described below.

## Windows (ZIP)

Eight Windows ZIP variants cover PHP 8.2–8.5, each in TS and NTS mode, on x64.
The workflow uses the official PHP Windows SDK/toolchains and vendors DuckDB
1.5.6. Each ZIP contains `php_duckdb.dll`, `duckdb.dll`, both licenses,
installation instructions, and build metadata. The PHPT suite and a fresh
installation smoke test run before artifact upload.

See [Windows installation and maintainer instructions](windows/README.md).
Windows x86/ARM64 and MSI installers are not included. Existing published
tags are not modified or backfilled with these packages.

## Release assets

Publishing a GitHub Release from a version tag runs the packaging workflow
on the release commit. Linux and macOS targets rebuild from that tag on
**both amd64 and arm64**; Windows builds run on **x64**. Each build runs the
full applicable test suite, then the `release-assets` job attaches all
packages to the release.

Asset names add a distro suffix because deb filenames are identical across
distros. The package filename already encodes the architecture:
`_amd64`/`_arm64` for debs and `.x86_64`/`.aarch64` for rpms:

```text
php-duckdb_1.3.1-1_amd64.debian-sid.deb          (+ _arm64)
php-duckdb_1.3.1-1_amd64.debian-13-trixie.deb    (+ _arm64)
php-duckdb_1.3.1-1_amd64.ubuntu-24.04.deb        (+ _arm64)
php-duckdb_1.3.1-1_amd64.ubuntu-26.04.deb        (+ _arm64)
php-duckdb_1.3.1-1_amd64.ubuntu-devel.deb        (+ _arm64)
php8-duckdb-1.3.1-1.x86_64.opensuse-tumbleweed.rpm (+ .aarch64, .src.rpm)
php-pecl-duckdb-1.3.1-1.fc44.x86_64.fedora-44.rpm  (+ .aarch64, .src.rpm)
php-pecl-duckdb-1.3.1-1.el9.x86_64.almalinux-9-php8.4.rpm
  (AlmaLinux: os 9/10 × php 8.2/8.3/8.4/8.5 × x86_64/aarch64 — 16 RPMs)
php-duckdb-1.3.1-php8.4-macos12-x86_64.tar.gz
  (macOS: php 8.2/8.3/8.4/8.5 × x86_64/arm64 — 8 tarballs)
php-duckdb-<version>-php8.4-nts-vs17-windows-x64.zip
  (Windows: php 8.2/8.3/8.4/8.5 × ts/nts — 8 ZIPs)
```

The suffix does not affect installation with `dpkg -i` or `rpm -ivh`;
dpkg/rpm do not depend on the file name. The workflow listens for
`release: published`, so a bare git tag does not trigger it. Create the
release from the tag in the GitHub UI or with `gh release create`.

## Adding another distribution

Copy the closest existing target and adjust the distro-specific knobs:

| Knob | openSUSE example | What to check elsewhere |
| --- | --- | --- |
| Package name | `php8-duckdb` | Debian: `php-duckdb`, Fedora: `php-pecl-duckdb`, Alpine: `php8X-duckdb`, Arch: `php-duckdb` |
| PHP dev package | `php8-devel` | Debian: `php-dev`, Fedora: `php-devel`, Alpine: `php8X-dev` |
| Extension dir | `%{php_extdir}` macro | `php-config --extension-dir` works everywhere as fallback |
| Ini drop-in | `/etc/php8/conf.d/*.ini` | Debian: per-SAPI `conf.d` + `phpenmod`, Fedora: `/etc/php.d`, Alpine: `/etc/php8X/conf.d` |
| ABI runtime deps | `php(api)`/`php(zend-abi)` provides | Debian uses `phpapi-*` virtual packages |
| libduckdb | patched source SDK | use the shared builders and carry both patches and SDK provenance |
| libc | glibc | Alpine (musl): DuckDB ships no official musl binaries and musl builds are notably slower — build libduckdb from source there |

Non-negotiables whatever the distro:

- Run the test suite at build time (`make test` with `NO_INTERACTION=1`
  and `REPORT_EXIT_STATUS=1`), with an opt-out switch for restricted
  build environments.
- Smoke-test the *installed* package: load via the distro ini mechanism
  and run a query.
- Ship `LICENSE` and declare the ABI dependency on PHP, so a PHP minor
  upgrade forces a rebuild instead of a runtime crash.
