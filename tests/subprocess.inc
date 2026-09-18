<?php
/**
 * Resolve PHP CLI arguments that load duckdb in a subprocess, the same way
 * the current process loaded it.
 *
 * Resolution order: $DUCKDB_EXTENSION_PATH (set by tests/harness.php),
 * then the default extension dir. Returns null when duckdb cannot be
 * loaded by a subprocess.
 *
 * @return list<string>|null argv elements after the PHP binary
 */
function duckdb_subprocess_args(): ?array
{
    $extPath = getenv('DUCKDB_EXTENSION_PATH') ?: null;
    if ($extPath === null || !is_file($extPath)) {
        $candidate = PHP_EXTENSION_DIR . '/duckdb.so';
        $extPath = is_file($candidate) ? $candidate : null;
    }
    return $extPath === null ? null : ['-d', 'extension=' . $extPath];
}
