#!/bin/sh
# Experimental Oracle Solaris SDK: reuse the shared source and patch pins.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
: "${DUCKDB_SDK_PREFIX:?Set DUCKDB_SDK_PREFIX}"
: "${DUCKDB_BUILD_DIR:?Set DUCKDB_BUILD_DIR to an empty, dedicated directory}"
: "${DUCKDB_BUILD_JOBS:=2}"
: "${DUCKDB_DISABLE_UNITY:=OFF}"
: "${CC:=gcc}"
: "${CXX:=g++}"
case "$DUCKDB_BUILD_JOBS" in ''|*[!0-9]*|0) echo 'Invalid job count' >&2; exit 2 ;; esac
case "$DUCKDB_DISABLE_UNITY" in ON|OFF) ;; *) echo 'Invalid unity setting' >&2; exit 2 ;; esac
[ "$(uname -s)" = SunOS ] || { echo 'Requires native Solaris' >&2; exit 2; }
for tool in python3 curl gtar gpatch cmake gmake "$CC" "$CXX"; do
    command -v "$tool" >/dev/null || { echo "Missing tool: $tool" >&2; exit 2; }
done
# Refuse existing directories instead of removing arbitrary caller paths.
mkdir "$DUCKDB_BUILD_DIR"
work=$(CDPATH= cd -- "$DUCKDB_BUILD_DIR" && pwd)
manifest=$root/packaging/duckdb/source.json
pin() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$manifest" "$1"; }
hash() { python3 -c 'import hashlib,sys; h=hashlib.sha256(); f=open(sys.argv[1],"rb"); [h.update(b) for b in iter(lambda:f.read(1048576),b"")]; print(h.hexdigest())' "$1"; }
version=$(pin version)
commit=$(pin commit)
patch_file=$root/packaging/duckdb/$(pin patch)
archive=${DUCKDB_SOURCE_ARCHIVE:-$work/source.tar.gz}
if [ -z "${DUCKDB_SOURCE_ARCHIVE:-}" ]; then
    curl -fLsS --retry 4 --retry-all-errors --retry-max-time 300 \
        --connect-timeout 20 --max-time 180 "$(pin url)" -o "$archive"
fi
[ "$(hash "$archive")" = "$(pin sha256)" ] || { echo 'Source SHA-256 mismatch' >&2; exit 1; }
mkdir "$work/source"
gtar -xzf "$archive" -C "$work/source" --strip-components=1
(cd "$work/source" && gpatch -p1 -F 0 -t < "$patch_file")
cmake -S "$work/source" -B "$work/build" -G 'Unix Makefiles' \
    -DCMAKE_MAKE_PROGRAM="$(command -v gmake)" -DCMAKE_BUILD_TYPE=Release \
    -DCMAKE_C_COMPILER="$CC" -DCMAKE_CXX_COMPILER="$CXX" \
    -DCMAKE_C_FLAGS="${CFLAGS:--m64}" -DCMAKE_CXX_FLAGS="${CXXFLAGS:--m64}" \
    -DCMAKE_SHARED_LINKER_FLAGS="${LDFLAGS:-} -lsocket -lnsl" \
    -DOVERRIDE_GIT_DESCRIBE="v$version" -DGIT_COMMIT_HASH="$(printf '%.10s' "$commit")" \
    '-DBUILD_EXTENSIONS=json;icu;autocomplete' -DENABLE_EXTENSION_AUTOLOADING=ON \
    -DENABLE_EXTENSION_AUTOINSTALL=ON -DNATIVE_ARCH=OFF \
    -DDISABLE_UNITY="$DUCKDB_DISABLE_UNITY" -DBUILD_SHELL=OFF \
    -DBUILD_UNITTESTS=OFF -DBUILD_BENCHMARKS=OFF
cmake --build "$work/build" --target duckdb --parallel "$DUCKDB_BUILD_JOBS"
metadata=$DUCKDB_SDK_PREFIX/share/duckdb-sdk
mkdir -p "$DUCKDB_SDK_PREFIX/include" "$DUCKDB_SDK_PREFIX/lib" "$metadata"
cp "$work/source/src/include/duckdb.h" "$DUCKDB_SDK_PREFIX/include/"
cp "$work/build/src/libduckdb.so" "$DUCKDB_SDK_PREFIX/lib/"
cp "$work/source/LICENSE" "$metadata/LICENSE.duckdb"
cp "$manifest" "$metadata/source.json"
cp "$patch_file" "$metadata/nullable-bitpacking.patch"
{
    echo "source_commit=$commit; source_sha256=$(pin sha256)"
    echo "patch_sha256=$(hash "$patch_file"); builder_sha256=$(hash "$0")"
    echo 'builtins=core_functions,parquet,json,icu,autocomplete; native_arch=OFF'
    echo "unity_disabled=$DUCKDB_DISABLE_UNITY; cflags=${CFLAGS:--m64}; cxxflags=${CXXFLAGS:--m64}"
    echo "ldflags=${LDFLAGS:-} -lsocket -lnsl"
    uname -a
    "$CC" --version
    "$CXX" --version
    cmake --version
} > "$metadata/build.txt"
python3 - "$DUCKDB_SDK_PREFIX" <<'PY'
import hashlib, json, pathlib, sys
prefix = pathlib.Path(sys.argv[1])
names = ['include/duckdb.h', 'lib/libduckdb.so',
         'share/duckdb-sdk/LICENSE.duckdb', 'share/duckdb-sdk/source.json',
         'share/duckdb-sdk/nullable-bitpacking.patch']
pins = {name: hashlib.sha256((prefix / name).read_bytes()).hexdigest() for name in names}
(prefix / 'share/duckdb-sdk/artifacts.json').write_text(json.dumps(pins, indent=2) + '\n')
PY
