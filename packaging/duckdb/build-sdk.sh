#!/bin/sh
# Build the pinned, patched DuckDB shared C API SDK. Never include C++ headers
# in the installed SDK; PHP extension builds use duckdb.h and the shared library.
set -eu

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
prefix=${DUCKDB_SDK_PREFIX:-/opt/duckdb}
work_dir=${DUCKDB_BUILD_DIR:-/tmp/php-duckdb-sdk-build}
jobs=${DUCKDB_BUILD_JOBS:-2}
disable_unity=${DUCKDB_DISABLE_UNITY:-OFF}
case "$disable_unity" in ON|OFF) ;; *) echo "DUCKDB_DISABLE_UNITY must be ON or OFF" >&2; exit 2 ;; esac
while [ "$#" -gt 0 ]; do
    case "$1" in
        --prefix|--work-dir|--jobs)
            [ "$#" -ge 2 ] || { echo "Missing value for $1" >&2; exit 2; }
            case "$1" in
                --prefix) prefix=$2 ;;
                --work-dir) work_dir=$2 ;;
                --jobs) jobs=$2 ;;
            esac
            shift 2 ;;
        --help)
            echo "Usage: $0 [--prefix PATH] [--work-dir PATH] [--jobs N]"
            exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
case "$jobs" in ''|*[!0-9]*|0) echo "Jobs must be a positive integer" >&2; exit 2 ;; esac
[ -n "$prefix" ] && [ -n "$work_dir" ] && [ "$work_dir" != / ] || {
    echo "Prefix and work directory must be nonempty; work directory cannot be /" >&2; exit 2;
}
for tool in python3 curl tar patch cmake make; do
    command -v "$tool" >/dev/null || { echo "Required tool missing: $tool" >&2; exit 2; }
done

manifest=$script_dir/source.json
pin() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$manifest" "$1"; }
hash() { python3 -c 'import hashlib,sys; h=hashlib.sha256(); f=open(sys.argv[1],"rb"); [h.update(b) for b in iter(lambda:f.read(1048576),b"")]; print(h.hexdigest())' "$1"; }
version=$(pin version)
source_url=$(pin url)
source_hash=$(pin sha256)
source_commit=$(pin commit)
patch_file=$script_dir/$(pin patch)
[ -f "$patch_file" ] || { echo "Pinned DuckDB patch missing: $patch_file" >&2; exit 2; }
patch_hash=$(hash "$patch_file")
platform=$(uname -s)
case "$platform" in
    Linux) library=libduckdb.so ;;
    Darwin) library=libduckdb.dylib ;;
    *) echo "Unsupported platform: $platform (Windows needs the PowerShell builder)" >&2; exit 2 ;;
esac
cc=${CC:-cc}
cxx=${CXX:-c++}
command -v "$cc" >/dev/null && command -v "$cxx" >/dev/null || {
    echo "C and C++ compilers are required" >&2; exit 2;
}
mkdir -p "$work_dir"
work_dir=$(CDPATH= cd -- "$work_dir" && pwd)
metadata=$work_dir/expected-build.txt
{
    echo "version=$version"
    echo "source_sha256=$source_hash"
    echo "source_commit=$source_commit"
    echo "manifest_sha256=$(hash "$manifest")"
    echo "patch_sha256=$patch_hash"
    echo "builder_sha256=$(hash "$script_dir/build-sdk.sh")"
    echo "platform=$platform/$(uname -m)"
    if [ "$platform" = Linux ]; then
        [ ! -f /etc/os-release ] || cat /etc/os-release
        ldd --version 2>&1 || true
    else
        sw_vers
        xcrun --show-sdk-version
    fi
    echo "cc=$cc; cxx=$cxx"
    "$cc" --version
    "$cxx" --version
    "$cxx" -dumpmachine
    cmake --version
    echo "build=Release; generator=Unix Makefiles; native_arch=OFF; disable_unity=$disable_unity"
    echo "builtins=core_functions,parquet,json,icu,autocomplete; autoload=ON; autoinstall=ON"
    echo "cflags=${CFLAGS:-}; cxxflags=${CXXFLAGS:-}; ldflags=${LDFLAGS:-}"
    echo "osx_architectures=${DUCKDB_OSX_ARCHITECTURES:-$(uname -m)}"
    echo "osx_deployment_target=${MACOSX_DEPLOYMENT_TARGET:-11.0}"
} > "$metadata"
fingerprint=$(hash "$metadata")
sdk_metadata=$prefix/share/duckdb-sdk
if [ -f "$sdk_metadata/build.txt" ] && cmp -s "$metadata" "$sdk_metadata/build.txt"; then
    if python3 - "$prefix" "$library" <<'PY'
import hashlib, json, pathlib, sys
prefix = pathlib.Path(sys.argv[1])
try:
    pins = json.loads((prefix / "share/duckdb-sdk/artifacts.json").read_text())
    required = {"include/duckdb.h", "lib/" + sys.argv[2],
                "share/duckdb-sdk/LICENSE.duckdb", "share/duckdb-sdk/source.json",
                "share/duckdb-sdk/nullable-bitpacking.patch"}
    assert set(pins) == required
    for name, expected in pins.items():
        assert hashlib.sha256((prefix / name).read_bytes()).hexdigest() == expected
except (OSError, ValueError, AssertionError):
    sys.exit(1)
PY
    then
        echo "Reusing verified DuckDB SDK $fingerprint at $prefix"
        exit 0
    fi
fi

archive=${DUCKDB_SOURCE_ARCHIVE:-$work_dir/duckdb-$version.tar.gz}
if [ ! -f "$archive" ]; then
    [ -z "${DUCKDB_SOURCE_ARCHIVE:-}" ] || { echo "Source archive missing: $archive" >&2; exit 2; }
    curl -fLsS --retry 4 --retry-all-errors --retry-max-time 300 \
        --connect-timeout 20 --max-time 180 "$source_url" -o "$archive.part"
    mv "$archive.part" "$archive"
fi
[ "$(hash "$archive")" = "$source_hash" ] || { echo "DuckDB source SHA-256 mismatch" >&2; exit 1; }
source_dir=$work_dir/source
build_dir=$work_dir/build
rm -rf "$source_dir" "$build_dir"
mkdir -p "$source_dir"
tar -xzf "$archive" -C "$source_dir" --strip-components=1
(cd "$source_dir" && patch -p1 -F 0 -t < "$patch_file")

set -- -S "$source_dir" -B "$build_dir" -G "Unix Makefiles" \
    -DCMAKE_BUILD_TYPE=Release -DCMAKE_C_COMPILER="$cc" -DCMAKE_CXX_COMPILER="$cxx" \
    -DCMAKE_C_FLAGS="${CFLAGS:-}" -DCMAKE_CXX_FLAGS="${CXXFLAGS:-}" \
    -DCMAKE_SHARED_LINKER_FLAGS="${LDFLAGS:-}" -DOVERRIDE_GIT_DESCRIBE="v$version" \
    -DGIT_COMMIT_HASH="$(printf '%.10s' "$source_commit")" \
    -DBUILD_EXTENSIONS="json;icu;autocomplete" -DENABLE_EXTENSION_AUTOLOADING=ON \
    -DENABLE_EXTENSION_AUTOINSTALL=ON -DNATIVE_ARCH=OFF -DDISABLE_UNITY="$disable_unity" \
    -DBUILD_SHELL=OFF -DBUILD_UNITTESTS=OFF -DBUILD_BENCHMARKS=OFF
if [ "$platform" = Darwin ]; then
    set -- "$@" "-DCMAKE_OSX_ARCHITECTURES=${DUCKDB_OSX_ARCHITECTURES:-$(uname -m)}" \
        "-DCMAKE_OSX_DEPLOYMENT_TARGET=${MACOSX_DEPLOYMENT_TARGET:-11.0}"
fi
cmake "$@"
cmake --build "$build_dir" --target duckdb --parallel "$jobs"
mkdir -p "$prefix/include" "$prefix/lib" "$sdk_metadata"
cp "$source_dir/src/include/duckdb.h" "$prefix/include/duckdb.h"
cp "$build_dir/src/$library" "$prefix/lib/$library"
cp "$source_dir/LICENSE" "$sdk_metadata/LICENSE.duckdb"
cp "$manifest" "$sdk_metadata/source.json"
cp "$patch_file" "$sdk_metadata/nullable-bitpacking.patch"
cp "$metadata" "$sdk_metadata/build.txt"
python3 - "$prefix" "$library" <<'PY'
import hashlib, json, pathlib, sys
prefix = pathlib.Path(sys.argv[1])
names = ["include/duckdb.h", "lib/" + sys.argv[2],
         "share/duckdb-sdk/LICENSE.duckdb", "share/duckdb-sdk/source.json",
         "share/duckdb-sdk/nullable-bitpacking.patch"]
pins = {name: hashlib.sha256((prefix / name).read_bytes()).hexdigest() for name in names}
(prefix / "share/duckdb-sdk/artifacts.json").write_text(json.dumps(pins, indent=2) + "\n")
PY
echo "Installed patched DuckDB SDK $fingerprint at $prefix"
