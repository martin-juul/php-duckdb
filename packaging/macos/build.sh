#!/bin/sh
# Build, test and package php-duckdb for macOS 12+ (x86_64 and arm64).
#
# Produces dist/php-duckdb-<ext>-php<php>-macos12-<arch>.tar.gz containing
# duckdb.so, the vendored libduckdb.dylib and install.sh. The extension
# version is read from php_duckdb.h and the PHP version from the php on
# PATH, so the tarball name can never drift from its contents.
#
# Build the patched source SDK with baseline CPU settings. Deployment and
# optional universal architecture settings are forwarded to the SDK builder.
# The packaged extension and library retain the macOS 12+ target.
#
# Needs phpize/php-config/php on PATH (Homebrew core php or the
# shivammathur/php tap). Invoke as: sh packaging/macos/build.sh
set -eu

cd "$(dirname "$0")/../.."

: "${MACOSX_DEPLOYMENT_TARGET:=12.0}"
export MACOSX_DEPLOYMENT_TARGET

arch=$(uname -m)
case "$arch" in
  x86_64|arm64) ;;
  *) echo "unsupported build arch: $arch" >&2; exit 1 ;;
esac

echo "==> Building patched DuckDB SDK"
sh packaging/duckdb/build-sdk.sh --prefix "$PWD/duckdb-sdk" \
  --work-dir "${DUCKDB_BUILD_DIR:-/tmp/php-duckdb-macos-engine}" \
  --jobs "${DUCKDB_BUILD_JOBS:-2}"

echo "==> Building duckdb.so (MACOSX_DEPLOYMENT_TARGET=$MACOSX_DEPLOYMENT_TARGET)"
phpize
./configure --with-duckdb="$PWD/duckdb-sdk"
make -j"$(sysctl -n hw.ncpu)"

old=$(otool -L modules/duckdb.so | awk '/libduckdb/ {print $1; exit}')
[ -n "$old" ] || { echo "modules/duckdb.so does not link libduckdb" >&2; exit 1; }
install_name_tool -change "$old" "@rpath/libduckdb.dylib" modules/duckdb.so
install_name_tool -add_rpath "@loader_path" modules/duckdb.so
codesign --force --sign - modules/duckdb.so
vtool -show-build modules/duckdb.so

echo "==> Running test suite"
DUCKDB_EXTENSION_PATH="$PWD/modules/duckdb.so" \
DYLD_LIBRARY_PATH="$PWD/duckdb-sdk/lib${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}" \
  make test NO_INTERACTION=1 REPORT_EXIT_STATUS=1 TESTS="--show-diff"

echo "==> Packaging tarball"
extver=$(sed -n 's/.*PHP_DUCKDB_VERSION "\([^"]*\)".*/\1/p' php_duckdb.h)
phpver=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
name="php-duckdb-${extver}-php${phpver}-macos12-${arch}"
rm -rf dist "$name"
mkdir -p dist "$name"
cp modules/duckdb.so duckdb-sdk/lib/libduckdb.dylib LICENSE packaging/macos/install.sh "$name/"
cp duckdb-sdk/share/duckdb-sdk/LICENSE.duckdb "$name/"
cp duckdb-sdk/share/duckdb-sdk/build.txt "$name/duckdb-build.txt"
cp duckdb-sdk/share/duckdb-sdk/source.json "$name/duckdb-source.json"
cp duckdb-sdk/share/duckdb-sdk/nullable-bitpacking.patch "$name/"
cp duckdb-sdk/share/duckdb-sdk/artifacts.json "$name/duckdb-sdk-artifacts.json"

# Give the vendored dylib an rpath-relative install name; duckdb.so will
# reference it via @rpath and resolve it through @loader_path (i.e. the
# extension dir), so the pair can be installed anywhere. Modifying a
# Mach-O invalidates its signature — re-sign ad hoc (required on arm64,
# harmless on x86_64).
install_name_tool -id "@rpath/libduckdb.dylib" "$name/libduckdb.dylib"
codesign --force --sign - "$name/libduckdb.dylib"
chmod +x "$name/install.sh"
tar -czf "dist/${name}.tar.gz" "$name"
rm -rf "$name"
echo "==> Built dist/${name}.tar.gz"
