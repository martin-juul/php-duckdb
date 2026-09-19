#
# spec file for package php8-duckdb
#
# Copyright (c) 2026 Martin Juul Christiansen
#
# All modifications and additions to the file contributed by third parties
# remain the property of their copyright owners, unless otherwise agreed
# upon. The license for this file, and modifications and additions to the
# file, is the same license as for the pristine package itself (MIT).
#

# libduckdb is not packaged for openSUSE yet, so the prebuilt upstream
# archive is used (same provenance as the project's Docker images).
# Switch to a system duckdb-devel package once the distribution ships one.
%define duckdb_version 1.5.5

# php8-devel ships rpm macros for php_extdir/php_cfgdir/php_core_api/
# php_zend_api on suse_version > 1500; define the paths by hand for older
# targets and non-SUSE rpmbuilds.
%if 0%{?suse_version} == 0 || 0%{?suse_version} <= 1500
%define php_extdir %(%{__php_config} --extension-dir)
%define php_cfgdir %{_sysconfdir}/php8/conf.d
%endif

# The test suite runs in %check by default; build with --without tests to
# skip it (e.g. in restricted build environments).
%bcond_without tests

Name:           php8-duckdb
Version:        1.2.0
Release:        0
Summary:        Native DuckDB driver for PHP
License:        MIT
Group:          Productivity/Databases/Tools
URL:            https://github.com/martin-juul/php-duckdb
Source0:        https://github.com/martin-juul/php-duckdb/archive/refs/tags/%{version}.tar.gz#/php-duckdb-%{version}.tar.gz
%ifarch x86_64
Source1:        https://github.com/duckdb/duckdb/releases/download/v%{duckdb_version}/libduckdb-linux-amd64.zip
%endif
%ifarch aarch64
Source1:        https://github.com/duckdb/duckdb/releases/download/v%{duckdb_version}/libduckdb-linux-arm64.zip
%endif
# DuckDB ships prebuilt libduckdb archives for linux amd64/arm64 only.
ExclusiveArch:  x86_64 aarch64
BuildRequires:  autoconf
BuildRequires:  gcc-c++
BuildRequires:  make
BuildRequires:  unzip
BuildRequires:  php8-devel >= 8.2
Requires:       php(api) = %{php_core_api}
Requires:       php(zend-abi) = %{php_zend_api}
Provides:       php-duckdb = %{version}

%description
A native PHP extension (C++/Zend API) that embeds DuckDB, the in-process
analytical database, via the stable DuckDB C API: synchronous, streaming
and asynchronous queries, prepared statements with positional and named
parameters, PDO-style transaction helpers, a bulk appender, and a typed
exception hierarchy mirroring DuckDB's error categories.

This package ships libduckdb %{duckdb_version} alongside the extension,
because openSUSE does not package DuckDB yet.

%prep
%autosetup -p1 -n php-duckdb-%{version}
# Stage the DuckDB C API library in the layout --with-duckdb=DIR expects
# (include/duckdb.h + lib/libduckdb.so), same as the Dockerfile.
mkdir -p duckdb-sdk/include duckdb-sdk/lib
unzip -o %{SOURCE1} -d duckdb-sdk
mv duckdb-sdk/duckdb.h duckdb-sdk/include/
mv duckdb-sdk/libduckdb.so duckdb-sdk/lib/

%build
export CXXFLAGS="%{optflags}"
%{__phpize}
%configure --with-duckdb="$PWD/duckdb-sdk"
%make_build

%install
make install-modules INSTALL_ROOT=%{buildroot}
install -D -m 0755 duckdb-sdk/lib/libduckdb.so %{buildroot}%{_libdir}/libduckdb.so
mkdir -p %{buildroot}%{php_cfgdir}
cat > %{buildroot}%{php_cfgdir}/duckdb.ini <<'EOF'
; comment out next line to disable the duckdb extension
extension = duckdb.so
EOF

%if %{with tests}
%check
# The extension under test resolves libduckdb from the staged SDK (it is
# not installed system-wide at this point).
export LD_LIBRARY_PATH="$PWD/duckdb-sdk/lib"
export NO_INTERACTION=1 REPORT_EXIT_STATUS=1
%make_build test PHP_EXECUTABLE=%{__php} TEST_PHP_EXECUTABLE=%{__php}
%endif

%files
%license LICENSE
%doc README.md
%config(noreplace) %{php_cfgdir}/duckdb.ini
%{php_extdir}/duckdb.so
%{_libdir}/libduckdb.so

%changelog
* Sat Sep 19 2026 Martin Juul Christiansen <code@juul.xyz> - 1.2.0-0
- Initial openSUSE package.
