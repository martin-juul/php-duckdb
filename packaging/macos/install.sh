#!/bin/sh
# Install php-duckdb for the PHP that is first on PATH (Homebrew core php
# or the shivammathur/php tap). Copies duckdb.so and the vendored
# libduckdb.dylib into PHP's extension dir (duckdb.so finds the dylib via
# @loader_path) and writes 40-duckdb.ini into PHP's ini scan dir.
#
#   tar -xzf php-duckdb-*-macos12-*.tar.gz
#   sh php-duckdb-*/install.sh
#
# Make sure the tarball's PHP version matches the php on PATH — a
# duckdb.so only loads into the exact PHP minor it was built against
# (php -m will report the mismatch as a failed module load). sudo is used
# automatically if the extension dir is not writable.
set -eu
cd "$(dirname "$0")"

command -v php >/dev/null 2>&1 || { echo "error: php not found on PATH" >&2; exit 1; }
command -v php-config >/dev/null 2>&1 || { echo "error: php-config not found on PATH" >&2; exit 1; }

extdir=$(php-config --extension-dir)
scandir=$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR ?: "";')

SUDO=""
if [ ! -w "$extdir" ]; then
  command -v sudo >/dev/null 2>&1 || { echo "error: $extdir is not writable and sudo is unavailable" >&2; exit 1; }
  SUDO="sudo"
fi

$SUDO cp duckdb.so libduckdb.dylib "$extdir/"

if [ -n "$scandir" ]; then
  # The scan dir is not always created by the PHP package (e.g. a fresh
  # Homebrew install has no conf.d until the first extension drops one).
  $SUDO mkdir -p "$scandir"
  echo "extension=duckdb.so" | $SUDO tee "$scandir/40-duckdb.ini" > /dev/null
else
  echo "note: this PHP has no ini scan dir; add 'extension=duckdb.so' to php.ini manually"
fi

php -m | grep -i duckdb
echo "php-duckdb installed for PHP $(php -r 'echo PHP_VERSION;') ($extdir)"
