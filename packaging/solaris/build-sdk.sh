#!/bin/sh
# Experimental Oracle Solaris SDK: reuse the shared source and patch pins.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
: "${DUCKDB_SDK_PREFIX:?Set DUCKDB_SDK_PREFIX}"
: "${DUCKDB_BUILD_DIR:?Set DUCKDB_BUILD_DIR to an empty, dedicated directory}"
: "${DUCKDB_BUILD_JOBS:=$(python3 "$root/packaging/resources/jobs.py" --profile sdk)}"
: "${DUCKDB_DISABLE_UNITY:=OFF}"
: "${CC:=gcc}"
: "${CXX:=g++}"
case "$DUCKDB_BUILD_JOBS" in
    ''|0*|*[!0-9]*)
        echo 'Invalid job count' >&2
        exit 2
        ;;
esac
case "$DUCKDB_DISABLE_UNITY" in
    ON|OFF)
        ;;
    *)
        echo 'Invalid unity setting' >&2
        exit 2
        ;;
esac
[ "$(uname -s)" = SunOS ] || {
    echo 'Requires native Solaris' >&2
    exit 2
}
for tool in python3 curl gtar gpatch cmake gmake "$CC" "$CXX"; do
    command -v "$tool" >/dev/null || {
        echo "Missing tool: $tool" >&2
        exit 2
    }
done
# Refuse existing directories instead of removing arbitrary caller paths.
mkdir "$DUCKDB_BUILD_DIR"
work=$(CDPATH= cd -- "$DUCKDB_BUILD_DIR" && pwd)
manifest=$root/packaging/duckdb/source.json
pin() {
    python3 - "$manifest" "$1" <<'PYTHON'
import json
import sys

with open(sys.argv[1]) as source:
    manifest = json.load(source)

print(manifest[sys.argv[2]])
PYTHON
}

hash() {
    python3 - "$1" <<'PYTHON'
import hashlib
import sys

checksum = hashlib.sha256()
with open(sys.argv[1], "rb") as source:
    for block in iter(lambda: source.read(1048576), b""):
        checksum.update(block)

print(checksum.hexdigest())
PYTHON
}

version=$(pin version)
commit=$(pin commit)
patch_file=$root/packaging/duckdb/$(pin patch)
arrow_patch_file=$root/packaging/duckdb/patches/arrow-geometry.patch
copy_function_patch_file=$root/packaging/duckdb/patches/c-api-copy-functions.patch
for required_patch in "$patch_file" "$arrow_patch_file" "$copy_function_patch_file"; do
    [ -f "$required_patch" ] || {
        echo "Pinned DuckDB patch missing: $required_patch" >&2
        exit 2
    }
done
archive=${DUCKDB_SOURCE_ARCHIVE:-$work/source.tar.gz}
if [ -z "${DUCKDB_SOURCE_ARCHIVE:-}" ]; then
    curl -fLsS --retry 4 --retry-all-errors --retry-max-time 300 \
        --connect-timeout 20 --max-time 180 "$(pin url)" -o "$archive"
fi
[ "$(hash "$archive")" = "$(pin sha256)" ] || {
    echo 'Source SHA-256 mismatch' >&2
    exit 1
}
mkdir "$work/source"
gtar -xzf "$archive" -C "$work/source" --strip-components=1
(cd "$work/source" && gpatch -p1 -F 0 -t < "$patch_file")
(cd "$work/source" && gpatch -p1 -F 0 -t < "$arrow_patch_file")
(cd "$work/source" && gpatch -p1 -F 0 -t < "$copy_function_patch_file")
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
cp "$arrow_patch_file" "$metadata/arrow-geometry.patch"
cp "$copy_function_patch_file" "$metadata/c-api-copy-functions.patch"
{
    echo "source_commit=$commit; source_sha256=$(pin sha256)"
    echo "patch_sha256=$(hash "$patch_file"); arrow_patch_sha256=$(hash "$arrow_patch_file")"
    echo "copy_function_patch_sha256=$(hash "$copy_function_patch_file")"
    echo "builder_sha256=$(hash "$0")"
    echo 'builtins=core_functions,parquet,json,icu,autocomplete; native_arch=OFF'
    echo "unity_disabled=$DUCKDB_DISABLE_UNITY; cflags=${CFLAGS:--m64}; cxxflags=${CXXFLAGS:--m64}"
    echo "ldflags=${LDFLAGS:-} -lsocket -lnsl"
    uname -a
    "$CC" --version
    "$CXX" --version
    cmake --version
} > "$metadata/build.txt"
python3 - "$DUCKDB_SDK_PREFIX" <<'PY'
import hashlib
import json
import pathlib
import sys

prefix = pathlib.Path(sys.argv[1])
names = [
    'include/duckdb.h',
    'lib/libduckdb.so',
    'share/duckdb-sdk/LICENSE.duckdb',
    'share/duckdb-sdk/source.json',
    'share/duckdb-sdk/nullable-bitpacking.patch',
    'share/duckdb-sdk/arrow-geometry.patch',
    'share/duckdb-sdk/c-api-copy-functions.patch',
]
pins = {name: hashlib.sha256((prefix / name).read_bytes()).hexdigest() for name in names}
(prefix / 'share/duckdb-sdk/artifacts.json').write_text(json.dumps(pins, indent=2) + '\n')
PY
