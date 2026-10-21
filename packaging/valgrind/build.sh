#!/usr/bin/env bash
# Build an explicit, private Valgrind with the AMD64 stack-unwind fix.
set -euo pipefail

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(CDPATH= cd -- "$script_dir/../.." && pwd)
cache_dir="${XDG_CACHE_HOME:-"$HOME/.cache"}/php-duckdb"
version=3.27.1
archive_name="valgrind-$version.tar.bz2"
archive_sha256=5d589152eb8071c02feab8ce6ab719e431a1fbc3e2b1700f5432632a8b9264dc
archive_url="https://sourceware.org/pub/valgrind/$archive_name"
prefix=${1:-"$cache_dir/valgrind-$version-stack-unwind"}

if [[ $# -gt 1 ]]; then
    echo "Usage: $0 [installation-prefix]" >&2
    exit 64
fi

if [[ $(uname -s) != Linux || $(uname -m) != x86_64 ]]; then
    echo 'This builder is validated for Linux x86_64 only.' >&2
    exit 1
fi

if [[ $prefix != /* ]]; then
    prefix="$PWD/$prefix"
fi

build_jobs=${DUCKDB_JOBS:-$(python3 "$repo_root/packaging/resources/jobs.py" --profile extension)}
if [[ ! $build_jobs =~ ^[1-9][0-9]*$ ]]; then
    echo 'DUCKDB_JOBS must be a positive integer.' >&2
    exit 1
fi

mkdir -p "$cache_dir/downloads"
archive="$cache_dir/downloads/$archive_name"
build_dir=$(mktemp -d "$cache_dir/valgrind-build.XXXXXX")

finish() {
    result=$?
    rm -f "$build_dir/archive.download"
    if [[ $result -eq 0 ]]; then
        rm -rf "$build_dir"
    else
        echo "Build files and logs retained at $build_dir" >&2
    fi
}
trap finish EXIT

verify_archive() {
    python3 - "$1" "$archive_sha256" <<'PY'
import hashlib
from pathlib import Path
import sys

actual = hashlib.sha256(Path(sys.argv[1]).read_bytes()).hexdigest()
if actual != sys.argv[2]:
    raise SystemExit(f"Valgrind archive SHA256 mismatch: {actual}")
PY
}

if [[ ! -f $archive ]]; then
    curl --fail --location --connect-timeout 10 --max-time 120 \
        --retry 2 --retry-max-time 120 \
        "$archive_url" --output "$build_dir/archive.download"
    verify_archive "$build_dir/archive.download"
    mv "$build_dir/archive.download" "$archive"
fi
verify_archive "$archive"

tar -xjf "$archive" -C "$build_dir"
cd "$build_dir/valgrind-$version"
git apply "$repo_root/tests/valgrind-3.27.1-fiber-unwind.patch"

printf 'Building Valgrind %s with %s workers; logs: %s\n' "$version" "$build_jobs" "$build_dir"
if ! ./configure --prefix="$prefix" --enable-only64bit > "$build_dir/configure.log" 2>&1; then
    tail -n 40 "$build_dir/configure.log" >&2
    exit 1
fi
if ! make -j"$build_jobs" > "$build_dir/build.log" 2>&1; then
    tail -n 40 "$build_dir/build.log" >&2
    exit 1
fi
if ! make install > "$build_dir/install.log" 2>&1; then
    tail -n 40 "$build_dir/install.log" >&2
    exit 1
fi

printf 'Installed %s/bin/valgrind\n' "$prefix"
printf 'Validate explicitly: PATH="%s/bin:$PATH" php tests/harness.php --full\n' "$prefix"
