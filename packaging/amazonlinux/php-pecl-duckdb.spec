# Native Amazon Linux 2023 / 2027 preview PHP extension package.
# Both releases use namespaced PHP packages and a single active PHP toolchain.
%global pecl_name duckdb
%global upstream_version 1.3.1
%global duckdb_version 1.5.6

%if 0%{?amzn2027}
%{!?php_slot:%global php_slot 8.5}
%else
%{!?php_slot:%global php_slot 8.4}
%endif

# AL2023's PHP development package does not define these RPM macros.
%global __phpize /usr/bin/phpize
%global __phpconfig /usr/bin/php-config
%global ini_name 40-%{pecl_name}.ini
%global engine_dir %{_libdir}/php-duckdb
%global engine_name libphp-duckdb-engine.so

%bcond_without tests

Name:           php-pecl-%{pecl_name}
Version:        %{upstream_version}
Release:        1%{?dist}
Summary:        Native DuckDB driver for Amazon Linux PHP
License:        MIT
URL:            https://github.com/martin-juul/php-duckdb
Source0:        https://github.com/martin-juul/php-duckdb/archive/refs/tags/%{upstream_version}.tar.gz#/php-duckdb-%{upstream_version}.tar.gz
Source1:        https://codeload.github.com/duckdb/duckdb/tar.gz/refs/tags/v%{duckdb_version}#/duckdb-%{duckdb_version}.tar.gz
ExclusiveArch:  x86_64 aarch64

BuildRequires:  rpm-build
BuildRequires:  gcc-c++
BuildRequires:  make
BuildRequires:  libtool
BuildRequires:  autoconf
BuildRequires:  cmake
BuildRequires:  python3
BuildRequires:  patch
BuildRequires:  /usr/bin/curl
BuildRequires:  tar
BuildRequires:  gzip
BuildRequires:  gawk
BuildRequires:  patchelf
BuildRequires:  php%{php_slot}-devel
BuildRequires:  php%{php_slot}-cli
%if %{with tests}
BuildRequires:  php%{php_slot}-ffi
%endif

Requires:       php%{php_slot}-common
Requires:       php(zend-abi) = %{php_zend_api}
Requires:       php(api) = %{php_core_api}
Provides:       php-duckdb = %{version}
Provides:       php-pecl(DuckDB) = %{version}
Provides:       php-pie(martinjuul/duckdb) = %{version}

# eu-strip can corrupt a PHDR table relocated by patchelf. Retain native
# debug extraction and stripping, then finalize private engine metadata.
%global __os_install_post %{expand:%{__os_install_post}
patchelf --set-soname %{engine_name} "$RPM_BUILD_ROOT%{engine_dir}/%{engine_name}"
patchelf --replace-needed libduckdb.so %{engine_dir}/%{engine_name} "$RPM_BUILD_ROOT%{php_extdir}/%{pecl_name}.so"
patchelf --remove-rpath "$RPM_BUILD_ROOT%{php_extdir}/%{pecl_name}.so"
}

%description
Native PHP bindings for DuckDB with buffered, streaming and asynchronous
queries, prepared statements, bulk appending and Arrow interoperability.

The package builds the pinned DuckDB source with all repository patches
and installs the engine privately with a distinct SONAME. A distribution
DuckDB package is neither required nor replaced.

%prep
%autosetup -p1 -n php-duckdb-%{upstream_version}

ver=$(sed -n '/PHP_DUCKDB_VERSION/{s/.* "//;s/".*$//;p}' php_duckdb.h)
test "$ver" = "%{upstream_version}"
dver=$(python3 -c 'import json; print(json.load(open("packaging/duckdb/source.json"))["version"])')
test "$dver" = "%{duckdb_version}"
phpver=$(%{__php} -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')
test "$phpver" = "%{php_slot}"
case "$phpver" in
    8.2|8.3|8.4|8.5) ;;
    *) echo "Supported PHP versions are 8.2 through 8.5" >&2; exit 1 ;;
esac

cat > %{ini_name} <<'INI'
; Enable DuckDB with its privately packaged engine.
extension=duckdb.so
INI

%build
%{?set_build_flags}
sdk_jobs=${DUCKDB_BUILD_JOBS:-$(python3 packaging/resources/jobs.py --profile sdk)}
case "$sdk_jobs" in
    ''|0*|*[!0-9]*) echo "DUCKDB_BUILD_JOBS must be a positive integer" >&2; exit 1 ;;
esac
export DUCKDB_BUILD_JOBS="$sdk_jobs"
# GCC's native -flto=auto ignores cgroup quotas and CPU affinity.
CFLAGS=$(printf '%s' "$CFLAGS" | sed "s/-flto=auto/-flto=$sdk_jobs/g")
CXXFLAGS=$(printf '%s' "$CXXFLAGS" | sed "s/-flto=auto/-flto=$sdk_jobs/g")
LDFLAGS=$(printf '%s' "$LDFLAGS" | sed "s/-flto=auto/-flto=$sdk_jobs/g")
export CFLAGS CXXFLAGS LDFLAGS
DUCKDB_SOURCE_ARCHIVE="%{SOURCE1}" sh packaging/duckdb/build-sdk.sh \
    --prefix "$PWD/duckdb-sdk" --work-dir "$PWD/duckdb-engine-build"
extension_jobs=${DUCKDB_JOBS:-$(python3 packaging/resources/jobs.py --profile extension)}
case "$extension_jobs" in
    ''|0*|*[!0-9]*) echo "DUCKDB_JOBS must be a positive integer" >&2; exit 1 ;;
esac
CFLAGS=$(printf '%s' "$CFLAGS" | sed "s/-flto=[0-9][0-9]*/-flto=$extension_jobs/g")
CXXFLAGS=$(printf '%s' "$CXXFLAGS" | sed "s/-flto=[0-9][0-9]*/-flto=$extension_jobs/g")
LDFLAGS=$(printf '%s' "$LDFLAGS" | sed "s/-flto=[0-9][0-9]*/-flto=$extension_jobs/g")
export CFLAGS CXXFLAGS LDFLAGS
%{__phpize}
%configure --with-duckdb="$PWD/duckdb-sdk" --with-php-config=%{__phpconfig}
make -j"$extension_jobs"

%install
make INSTALL_ROOT=%{buildroot} install
install -Dpm 644 %{ini_name} %{buildroot}%{php_inidir}/%{ini_name}
install -Dpm 755 duckdb-sdk/lib/libduckdb.so %{buildroot}%{engine_dir}/%{engine_name}
# Removing an existing RPATH edits tags in place, so native eu-strip stays safe.
# Native RPATH QA runs before the final dependency/SONAME rewrite hook.
patchelf --remove-rpath %{buildroot}%{php_extdir}/%{pecl_name}.so

%check
# The staged absolute dependency is unavailable until RPM installation.
# Test an otherwise identical copy linked to the staged private engine.
cp %{buildroot}%{php_extdir}/%{pecl_name}.so modules/duckdb-check.so
patchelf --replace-needed %{engine_dir}/%{engine_name} \
    %{buildroot}%{engine_dir}/%{engine_name} modules/duckdb-check.so
%{__php} -n -d extension="$PWD/modules/duckdb-check.so" \
    -r 'if (!extension_loaded("duckdb")) { exit(1); }'

%if %{with tests}
# AL2023 retains these deprecated PHP 8.4+ directives in its stock php.ini.
# Keep native settings/extensions and all diagnostics; remove only those inputs
# from a private test configuration, leaving the installed config untouched.
if %{__php} -n -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
    cp /etc/php.ini test-php.ini
    sed -i '/^[[:space:]]*session\.\(sid_length\|sid_bits_per_character\)[[:space:]]*=/d' test-php.ini
    export PHPRC="$PWD/test-php.ini"
fi
# A previously installed DuckDB package must not load alongside the staged
# module. Retain every other native extension configuration for these checks.
mkdir -p test-php.d
for configuration in "%{php_inidir}"/*.ini; do
    if [ ! -f "$configuration" ]; then
        continue
    fi
    if [ "$configuration" = "%{php_inidir}/%{ini_name}" ]; then
        continue
    fi
    cp "$configuration" test-php.d/
done
export PHP_INI_SCAN_DIR="$PWD/test-php.d"
export TEST_PHP_ARGS='-d ffi.enable=true'
%{__php} tests/harness.php unit examples --duckdb-dir="$PWD/duckdb-sdk" \
    --extension="$PWD/modules/duckdb-check.so" --no-color
%endif

%files
%license LICENSE duckdb-sdk/share/duckdb-sdk/LICENSE.duckdb
%doc README.md packaging/amazonlinux
%doc duckdb-sdk/share/duckdb-sdk/build.txt duckdb-sdk/share/duckdb-sdk/source.json
%doc duckdb-sdk/share/duckdb-sdk/nullable-bitpacking.patch
%doc duckdb-sdk/share/duckdb-sdk/arrow-geometry.patch
%doc duckdb-sdk/share/duckdb-sdk/c-api-copy-functions.patch duckdb-sdk/share/duckdb-sdk/artifacts.json
%config(noreplace) %{php_inidir}/%{ini_name}
%{php_extdir}/%{pecl_name}.so
%dir %{engine_dir}
%{engine_dir}/%{engine_name}

%changelog
* Sun Oct 04 2026 Martin Juul Christiansen <code@juul.xyz> - 1.3.1-1
- Add native Amazon Linux 2023 and Amazon Linux 2027 preview packaging.
- Ship the pinned patched DuckDB engine privately and validate Arrow conversion.
