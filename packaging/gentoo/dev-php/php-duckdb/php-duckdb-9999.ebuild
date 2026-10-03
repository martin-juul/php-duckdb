# Copyright 2026 Martin Juul Christiansen
# Distributed under the terms of the GNU General Public License v2

EAPI=8

PHP_EXT_NAME="duckdb"
PHP_EXT_INI="yes"
PHP_INI_NAME="40-duckdb"
PHP_EXT_NEEDED_USE="cli"
USE_PHP="php8-2 php8-3 php8-4 php8-5"
PYTHON_COMPAT=( python3_{12..15} )
CHECKREQS_DISK_BUILD="20G"

inherit check-reqs php-ext-source-r3 python-any-r1 toolchain-funcs

DESCRIPTION="Native DuckDB bindings for PHP with a privately installed patched engine"
HOMEPAGE="https://github.com/martin-juul/php-duckdb"

# Portage must know the distfile at metadata time. The shared SDK builder
# verifies it against packaging/duckdb/source.json before compilation.
DUCKDB_SOURCE_VERSION="1.5.6"
DUCKDB_DISTFILE="duckdb-${DUCKDB_SOURCE_VERSION}.tar.gz"
SRC_URI="https://codeload.github.com/duckdb/duckdb/tar.gz/refs/tags/v${DUCKDB_SOURCE_VERSION} -> ${DUCKDB_DISTFILE}"
if [[ ${PV} == 9999 ]]; then
	EGIT_REPO_URI="https://github.com/martin-juul/php-duckdb.git"
	inherit git-r3
else
	SRC_URI+=" https://github.com/martin-juul/php-duckdb/archive/refs/tags/${PV}.tar.gz -> ${P}.tar.gz"
fi

LICENSE="MIT"
SLOT="0"
KEYWORDS=""
IUSE="test"
RESTRICT="!test? ( test )"

BDEPEND="
	${PYTHON_DEPS}
	app-arch/tar
	dev-build/cmake
	dev-build/make
	dev-util/patchelf
	net-misc/curl
	sys-devel/patch
	test? (
		php_targets_php8-2? ( dev-lang/php:8.2[ffi,sockets] )
		php_targets_php8-3? ( dev-lang/php:8.3[ffi,sockets] )
		php_targets_php8-4? ( dev-lang/php:8.4[ffi,sockets] )
		php_targets_php8-5? ( dev-lang/php:8.5[ffi,sockets] )
	)
"

pkg_setup() {
	check-reqs_pkg_setup
	python-any-r1_pkg_setup
	if tc-is-cross-compiler; then
		die "The shared SDK builder does not support cross compilation"
	fi
}

src_unpack() {
	if [[ ${PV} == 9999 ]]; then
		git-r3_src_unpack
	else
		unpack "${P}.tar.gz"
	fi
	# The engine archive stays in DISTDIR; the shared builder verifies and
	# extracts it once during configure, outside the per-PHP source copies.
}

src_configure() {
	local sdk="${WORKDIR}/duckdb-sdk"
	if [[ ! -f ${S}/packaging/duckdb/build-sdk.sh ]]; then
		die "Source lacks the patched SDK builder; select a revision containing it"
	fi
	CC="$(tc-getCC)" CXX="$(tc-getCXX)" \
		DUCKDB_SOURCE_ARCHIVE="${DISTDIR}/${DUCKDB_DISTFILE}" \
		sh "${S}/packaging/duckdb/build-sdk.sh" \
		--prefix "${sdk}" --work-dir "${WORKDIR}/duckdb-engine" --jobs 2 \
		|| die "Patched DuckDB SDK build failed"
	local PHP_EXT_ECONF_ARGS=( --with-duckdb="${sdk}" )
	php-ext-source-r3_src_configure
}

src_test() {
	local slot
	for slot in $(php_get_slots); do
		php_init_slot_env "${slot}"
		LD_LIBRARY_PATH="${WORKDIR}/duckdb-sdk/lib${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}" \
			DUCKDB_EXTENSION_PATH="${PWD}/modules/duckdb.so" \
			TEST_PHP_EXECUTABLE="${PHPCLI}" PHP_EXECUTABLE="${PHPCLI}" \
			TEST_PHP_ARGS="-d ffi.enable=true" \
			NO_INTERACTION=1 REPORT_EXIT_STATUS=1 emake test
	done
}

src_install() {
	php-ext-source-r3_src_install
	local libdir="/usr/$(get_libdir)/php-duckdb"
	local engine="${EPREFIX}${libdir}/libphp-duckdb-engine.so"
	exeinto "${libdir}"
	newexe "${WORKDIR}/duckdb-sdk/lib/libduckdb.so" libphp-duckdb-engine.so
	patchelf --set-soname libphp-duckdb-engine.so "${ED}${libdir}/libphp-duckdb-engine.so" || die

	local slot
	for slot in $(php_get_slots); do
		php_init_slot_env "${slot}"
		patchelf --replace-needed libduckdb.so "${engine}" "${D}${EXT_DIR}/duckdb.so" || die
		patchelf --remove-rpath "${D}${EXT_DIR}/duckdb.so" || die
	done
	docinto duckdb-sdk
	dodoc "${WORKDIR}/duckdb-sdk/share/duckdb-sdk/"*
}
