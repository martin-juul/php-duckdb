# Distribution packaging

Native packages for Linux distributions live here, one directory per
distribution family:

```
packaging/
  opensuse/
    php-duckdb.spec     # RPM spec — openSUSE Tumbleweed/Leap, SLE
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
   spec and the project's Docker images do.

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
sudo zypper install rpm-build php8-devel gcc-c++ make autoconf unzip

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

## Adding another distribution

Copy the closest existing target and adjust the distro-specific knobs:

| Knob | openSUSE example | What to check elsewhere |
|---|---|---|
| Package name | `php8-duckdb` | Debian: `php-duckdb`, Fedora: `php-duckdb`, Alpine: `php8X-duckdb`, Arch: `php-duckdb` |
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
