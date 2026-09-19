# Fedora spec file for php-pecl-duckdb
#
# Copyright (c) 2026 Martin Juul Christiansen
#
# License: MIT
# http://opensource.org/licenses/MIT
#

%global pecl_name        duckdb
%global upstream_version 1.2.0

# libduckdb is not packaged for Fedora yet, so the prebuilt upstream
# archive is used (same provenance as the project's Docker images).
# Switch to a system duckdb-devel package once the distribution ships one.
%global duckdb_version   1.5.5

# duckdb is a normal extension without load-order constraints: the
# standard PECL ini priority is 40.
%global ini_name  40-%{pecl_name}.ini

%bcond_without tests

Name:           php-pecl-%{pecl_name}
Version:        %{upstream_version}
Release:        1%{?dist}
Summary:        Native DuckDB driver for PHP
License:        MIT
URL:            https://github.com/martin-juul/php-duckdb
Source0:        https://github.com/martin-juul/php-duckdb/archive/refs/tags/%{upstream_version}.tar.gz#/php-duckdb-%{upstream_version}.tar.gz
%ifarch x86_64
Source1:        https://github.com/duckdb/duckdb/releases/download/v%{duckdb_version}/libduckdb-linux-amd64.zip
%endif
%ifarch aarch64
Source1:        https://github.com/duckdb/duckdb/releases/download/v%{duckdb_version}/libduckdb-linux-arm64.zip
%endif

# DuckDB ships prebuilt libduckdb archives for linux amd64/arm64 only.
ExclusiveArch:  x86_64 aarch64

BuildRequires:  chrpath
BuildRequires:  gcc-c++
BuildRequires:  make
BuildRequires:  libtool
BuildRequires:  unzip
BuildRequires: (php-devel >= 8.2 with php-devel < 8.6)

Requires:       php(zend-abi) = %{php_zend_api}
Requires:       php(api) = %{php_core_api}

# Extension
Provides:       php-%{pecl_name}          = %{version}
Provides:       php-%{pecl_name}%{?_isa}  = %{version}
# PECL
Provides:       php-pecl(DuckDB)          = %{version}
Provides:       php-pecl(DuckDB)%{?_isa}  = %{version}
# PIE
Provides:       php-pie(martinjuul/duckdb) = %{version}

%description
A native PHP extension (C++/Zend API) that embeds DuckDB, the in-process
analytical database, via the stable DuckDB C API: synchronous, streaming
and asynchronous queries, prepared statements with positional and named
parameters, PDO-style transaction helpers, a bulk appender, and a typed
exception hierarchy mirroring DuckDB's error categories.

This package ships libduckdb %{duckdb_version} alongside the extension,
because Fedora does not package DuckDB yet.

%prep
%autosetup -p1 -n php-duckdb-%{upstream_version}

# Guard against spec/extension version drift
ver=$(sed -n '/PHP_DUCKDB_VERSION/{s/.* "//;s/".*$//;p}' php_duckdb.h)
if test "$ver" != "%{upstream_version}"; then
   : Error: PHP_DUCKDB_VERSION is ${ver}, expecting %{upstream_version}
   exit 1
fi

# Stage the DuckDB C API library in the layout --with-duckdb=DIR expects
# (include/duckdb.h + lib/libduckdb.so), same as the Dockerfile.
mkdir -p duckdb-sdk/include duckdb-sdk/lib
unzip -o %{SOURCE1} -d duckdb-sdk
mv duckdb-sdk/duckdb.h duckdb-sdk/include/
mv duckdb-sdk/libduckdb.so duckdb-sdk/lib/

cat << 'EOF' >%{ini_name}
; Enable duckdb extension module
extension = duckdb.so
EOF

%build
%{__phpize}
# phpize Makefiles use INSTALL_ROOT; convert so %make_install works
sed -e 's/INSTALL_ROOT/DESTDIR/' -i build/Makefile.global

%configure \
    --with-duckdb="$PWD/duckdb-sdk" \
    --with-php-config=%{__phpconfig}
%make_build

%install
: Install config file
install -Dpm 644 %{ini_name} %{buildroot}%{php_inidir}/%{ini_name}

: Install the extension
%make_install

: Install libduckdb - vendored, no system package exists yet
install -Dpm 755 duckdb-sdk/lib/libduckdb.so %{buildroot}%{_libdir}/libduckdb.so

: Drop the build-tree runpath that PHP_ADD_LIBRARY_WITH_PATH bakes in
: libduckdb is resolved via ldconfig from %%{_libdir} at runtime
chrpath -d %{buildroot}%{php_extdir}/%{pecl_name}.so

%check
# The built/installed extension resolves libduckdb from the staged SDK
# (it is not installed system-wide at this point).
export LD_LIBRARY_PATH="$PWD/duckdb-sdk/lib"

: check if the extension can be loaded
%{__php} --no-php-ini \
    --define extension=%{buildroot}%{php_extdir}/%{pecl_name}.so \
    --modules | grep '^duckdb$'

: check if provided config file is usable
%{__php} --no-php-ini \
    -d extension_dir=%{buildroot}%{php_extdir} \
    -c %{buildroot}%{php_inidir}/%{ini_name} \
    --modules | grep '^duckdb$'

%if %{with tests}
: Upstream test suite
export NO_INTERACTION=1 REPORT_EXIT_STATUS=1
%make_build test PHP_EXECUTABLE=%{__php} TEST_PHP_EXECUTABLE=%{__php}
%else
: Test suite disabled
%endif

%files
%license LICENSE
%doc README.md
%config(noreplace) %{php_inidir}/%{ini_name}
%{php_extdir}/%{pecl_name}.so
%{_libdir}/libduckdb.so

%changelog
* Sat Sep 19 2026 Martin Juul Christiansen <code@juul.xyz> - 1.2.0-1
- Initial Fedora package.
