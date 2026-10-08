# Amazon Linux RPM

This recipe builds against Amazon Linux's native PHP packages and ships the
repository's pinned DuckDB engine with all patches. It installs the engine
at `/usr/lib64/php-duckdb/libphp-duckdb-engine.so` on x86_64 and aarch64, with
its own SONAME and an explicit extension dependency. A system `libduckdb.so`
is neither installed nor replaced.

| Release | PHP selector | Container image |
| --- | --- | --- |
| Amazon Linux 2023 | `8.2`, `8.3`, `8.4` or `8.5`; default `8.4` | `public.ecr.aws/amazonlinux/amazonlinux:2023` |
| Amazon Linux 2027 preview | `8.5` | `public.ecr.aws/amazonlinux/amazonlinux:2027` |

AWS documents the [native PHP versions in AL2023](https://docs.aws.amazon.com/linux/al2023/ug/php.html)
and [PHP 8.5 in AL2027](https://docs.aws.amazon.com/linux/al2027/ug/language-runtimes-php.html).
AL2027 is currently a [public preview](https://aws.amazon.com/about-aws/whats-new/2026/09/announcing-amazon-linux-2027/).
Its RPMs are for preview evaluation; rebuild and retest against changing
preview repositories. The [official AL2027 container instructions](https://docs.aws.amazon.com/linux/al2027/ug/container-base.html)
use the image listed above and DNF5.

## Build natively

Use the corresponding Amazon Linux release and architecture. Install one PHP
version at a time; the packages provide the active `/usr/bin/php`, `phpize`
and `php-config` commands. No Remi or EPEL repository is needed.

From the repository root, on AL2023 with PHP 8.4:

```bash
sudo dnf install -y --setopt=install_weak_deps=False \
  rpm-build php8.4-devel php8.4-cli php8.4-ffi gcc gcc-c++ make libtool \
  autoconf cmake python3 patch tar gzip git gawk patchelf findutils
```

On AL2027, replace `php8.4` with `php8.5`. The base images already provide
`curl`; on AL2023, its `curl-minimal` package conflicts with installing the
full `curl` package. The spec requires `/usr/bin/curl`, allowing either provider.

Stage the source snapshot and pinned engine archive:

```bash
version=$(awk '/^%global upstream_version/ {print $3}' packaging/amazonlinux/php-pecl-duckdb.spec)
duckdb_version=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["version"])')
source_url=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["url"])')
mkdir -p "$HOME/rpmbuild/SOURCES" "$HOME/rpmbuild/SPECS"
git archive --prefix="php-duckdb-${version}/" \
  -o "$HOME/rpmbuild/SOURCES/php-duckdb-${version}.tar.gz" HEAD
curl -fsSL --retry 4 --retry-all-errors --retry-max-time 300 \
  --connect-timeout 20 --max-time 180 \
  -o "$HOME/rpmbuild/SOURCES/duckdb-${duckdb_version}.tar.gz" "$source_url"
cp packaging/amazonlinux/php-pecl-duckdb.spec "$HOME/rpmbuild/SPECS/"
rpmbuild --define 'php_slot 8.4' -ba "$HOME/rpmbuild/SPECS/php-pecl-duckdb.spec"
```

Use a source revision containing the shared SDK builder and all engine
patches. Historical release archives through 1.3.1 do not contain the complete
packaging toolchain. The builder verifies the engine archive SHA-256 before
applying patches and building. To build AL2027, select `php_slot 8.5`.

Worker counts follow [available CPU and memory](../resources/README.md).
`DUCKDB_BUILD_JOBS` overrides engine workers, and `DUCKDB_JOBS` overrides
extension and harness workers. Native LTO linking uses the selected worker budget
for each build stage; GCC's automatic LTO parallelism ignores the container quota.
`DUCKDB_SDK_CACHE_DIR` enables the shared
verified SDK cache; cache entries remain specific to the release, compiler,
flags, source and patches.

## Install and validate

```bash
sudo dnf install -y "$HOME"/rpmbuild/RPMS/"$(uname -m)"/php-pecl-duckdb-*.rpm
php --ri duckdb
php packaging/smoke.php
patchelf --print-needed "$(php-config --extension-dir)/duckdb.so"
patchelf --print-soname /usr/lib64/php-duckdb/libphp-duckdb-engine.so
```

The extension must require `/usr/lib64/php-duckdb/libphp-duckdb-engine.so`,
and that library must have SONAME `libphp-duckdb-engine.so`. Loading the
installed package must work without `LD_LIBRARY_PATH` or a build directory.
The shared smoke test checks query execution and lossless Arrow conversion,
including geometry CRS metadata, using the installed extension and engine.

The spec's `%check` runs the full PHPT suite and examples through
`php tests/harness.php`, enabling FFI tests. Optional async integrations report
skips when their runtime extensions or Composer libraries are absent.
On PHP 8.4 and later, the check uses a copy of the native `php.ini` with only
the deprecated `session.sid_length` and `session.sid_bits_per_character`
directives removed. Other settings, loaded extensions and diagnostics remain
active; the installed configuration stays untouched. A private scan directory
retains all native extension configurations except the package's own
`40-duckdb.ini`; the harness explicitly loads the staged module, so a previous
installed version cannot load alongside it.

Native container validation should build the binary RPM and source RPM, run
`%check`, install the result, and run the commands above on each release and
architecture. Run the repository's full harness in a development environment
with Valgrind and the relevant optional runtimes before publishing native changes.

## Validated native packages

On 2026-10-04, x86_64 containers built and installed this recipe with the
pinned, patched SDK. Every configuration passed 92 PHPT tests with four
optional integration skips, all 21 examples, and the installed-package smoke
and private-engine loader checks.

| Amazon Linux | Native PHP versions validated |
| --- | --- |
| 2023 | 8.2.33, 8.3.33, 8.4.25, 8.5.10 |
| 2027 preview | 8.5.10 |

FFI tests ran in every configuration. The four skips require Swoole,
AMPHP, ReactPHP or True Async, which were absent from these package build
containers. SDK compilation and LTO linking used four workers; final extension
builds and tests used one worker per container. The private engine loaded
without `LD_LIBRARY_PATH`. The aarch64 CI matrix is configured; local native
validation covered x86_64.
