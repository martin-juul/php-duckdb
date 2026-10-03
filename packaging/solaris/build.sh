#!/bin/sh
# Build an experimental Oracle Solaris 11.4 amd64 IPS archive, without installing it.
set -eu
if [ "${1:-}" = --help ]; then
    echo 'PHP_FMRI=pkg:/... BUILD_DIR=/absolute/new/path sh packaging/solaris/build.sh'
    exit 0
fi
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
[ "$(uname -s)" = SunOS ] && [ "$(isainfo -k)" = amd64 ] || {
    echo 'Requires native Oracle Solaris amd64' >&2; exit 2;
}
python3 -c 'import pathlib; assert "Oracle Solaris 11.4" in pathlib.Path("/etc/release").read_text()' || {
    echo 'This recipe targets Oracle Solaris 11.4, not illumos' >&2; exit 2;
}
: "${BUILD_DIR:?Set BUILD_DIR to a new absolute directory}"
: "${PHP_FMRI:?Set PHP_FMRI to the installed PHP IPS package FMRI}"
: "${PHP:=php}"
: "${PHPIZE:=phpize}"
: "${PHP_CONFIG:=php-config}"
: "${PUBLISHER:=php-duckdb-local}"
: "${DUCKDB_BUILD_JOBS:=2}"
: "${CC:=gcc}"
: "${CXX:=g++}"
: "${CFLAGS:=-m64}"
: "${CXXFLAGS:=-m64}"
export CC CXX CFLAGS CXXFLAGS
case "$BUILD_DIR" in /*) ;; *) echo 'BUILD_DIR must be absolute' >&2; exit 2 ;; esac
for tool in "$PHP" "$PHPIZE" "$PHP_CONFIG" gmake gtar python3 \
    pkg pkgrepo pkgsend pkgmogrify pkgfmt pkgdepend pkglint pkgrecv elfdump; do
    command -v "$tool" >/dev/null || { echo "Missing tool: $tool" >&2; exit 2; }
done
pkg info "$PHP_FMRI" > /dev/null
"$PHP" -r 'exit(PHP_INT_SIZE === 8 && PHP_VERSION_ID >= 80200 ? 0 : 1);' || {
    echo 'Requires 64-bit PHP 8.2+' >&2; exit 2;
}
[ "$("$PHP" -r 'echo PHP_VERSION;')" = "$("$PHP_CONFIG" --version)" ] || {
    echo 'PHP and php-config versions differ' >&2; exit 2;
}
minor=$("$PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
version=$(python3 -c 'import re,sys; print(re.search(r"PHP_DUCKDB_VERSION \"([^\"]+)\"",open(sys.argv[1]).read())[1])' "$root/php_duckdb.h")
prefix=/opt/php-duckdb/php-$minor
mkdir "$BUILD_DIR"
sdk=$BUILD_DIR/sdk
DUCKDB_SDK_PREFIX=$sdk DUCKDB_BUILD_DIR=$BUILD_DIR/engine \
    sh "$root/packaging/solaris/build-sdk.sh"
mkdir "$BUILD_DIR/extension"
# Copy only inputs; phpize and configure run outside the user's checkout.
for file in config.m4 duckdb.cpp php_duckdb.h php_duckdb_cxx_compat.h duckdb_arginfo.h; do
    cp "$root/$file" "$BUILD_DIR/extension/"
done
python3 - "$root" "$BUILD_DIR/extension" <<'PY'
import pathlib, shutil, sys
source, target = map(pathlib.Path, sys.argv[1:])
for path in (source / 'src').rglob('*'):
    if path.is_file() and path.suffix in {'.cpp', '.h'}:
        destination = target / path.relative_to(source)
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, destination)
PY
cd "$BUILD_DIR/extension"
"$PHPIZE"
PHP_RPATH=no LDFLAGS="${LDFLAGS:-} -Wl,-R,$prefix/lib" \
    ./configure --with-duckdb="$sdk" --with-php-config="$PHP_CONFIG"
gmake -j"$DUCKDB_BUILD_JOBS"
elfdump -d modules/duckdb.so > "$BUILD_DIR/extension-elf.txt"
python3 - "$BUILD_DIR/extension-elf.txt" "$prefix/lib" "$BUILD_DIR" <<'PY'
import pathlib, sys
lines = pathlib.Path(sys.argv[1]).read_text().splitlines()
paths = [line.split()[-1] for line in lines if 'RUNPATH' in line or 'RPATH' in line]
if not paths or any(path.split(':')[0] != sys.argv[2] for path in paths):
    raise SystemExit('Installed private library path must be first in ELF runtime search paths')
if any(sys.argv[3] in path for path in paths):
    raise SystemExit('ELF runtime search path contains a temporary build directory')
PY
LD_LIBRARY_PATH="$sdk/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
    "$PHP" -n -d "extension=$BUILD_DIR/extension/modules/duckdb.so" \
    "$root/packaging/solaris/smoke.php"
# Run the repository's PHPT harness against this exact module before packaging.
python3 - "$root" "$BUILD_DIR/extension" <<'PY'
import pathlib, shutil, sys
source, target = map(pathlib.Path, sys.argv[1:])
for directory in ['tests', 'examples']:
    for path in (source / directory).rglob('*'):
        if not path.is_file():
            continue
        relative = path.relative_to(source)
        include = path.suffix in {'.phpt', '.inc', '.supp', '.c'}
        include |= path.suffix == '.php' and (directory == 'examples'
                   or path.name == 'harness.php'
                   or relative.parts[1] in {'harness', 'stress'})
        if include:
            destination = target / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, destination)
PY
LD_LIBRARY_PATH="$sdk/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
    DUCKDB_EXTENSION_PATH="$BUILD_DIR/extension/modules/duckdb.so" \
    "$PHP" tests/harness.php unit --jobs="$DUCKDB_BUILD_JOBS" \
    --extension="$BUILD_DIR/extension/modules/duckdb.so" --duckdb-dir="$sdk"
proto=$BUILD_DIR/proto
mkdir -p "$proto$prefix/lib/php" "$proto$prefix/share"
cp modules/duckdb.so "$proto$prefix/lib/php/"
cp "$sdk/lib/libduckdb.so" "$proto$prefix/lib/"
cp -R "$sdk/share/duckdb-sdk" "$proto$prefix/share/"
cp "$root/LICENSE" "$proto$prefix/share/LICENSE.php-duckdb"
"$PHP" -i > "$proto$prefix/share/php-build.txt"
pkgsend generate "$proto" > "$BUILD_DIR/generated.p5m"
pkgmogrify -D "PUBLISHER=$PUBLISHER" -D "VERSION=$version" \
    -D "PHP_MINOR=$minor" -D "PHP_FMRI=$PHP_FMRI" -D "PREFIX=${prefix#/}" \
    "$BUILD_DIR/generated.p5m" "$root/packaging/solaris/package.mog" \
    > "$BUILD_DIR/package.p5m"
pkgfmt "$BUILD_DIR/package.p5m"
pkgdepend generate -m -d "$proto" "$BUILD_DIR/package.p5m" > "$BUILD_DIR/dependencies.p5m"
pkgdepend resolve -m "$BUILD_DIR/dependencies.p5m"
pkglint "$BUILD_DIR/dependencies.p5m.res"
pkgrepo create "$BUILD_DIR/repository"
pkgrepo set -s "$BUILD_DIR/repository" "publisher/prefix=$PUBLISHER"
pkgsend -s "$BUILD_DIR/repository" publish -d "$proto" "$BUILD_DIR/dependencies.p5m.res"
pkgrecv -s "$BUILD_DIR/repository" -a \
    -d "$BUILD_DIR/php-duckdb-$version-php$minor-solaris11.4-amd64.p5p" \
    "pkg://$PUBLISHER/library/php-duckdb-$minor@$version"
echo "Built IPS archive in $BUILD_DIR; native installation validation is still required."
