<?php
/**
 * Resolve PHP CLI arguments that load both duckdb and swoole in a
 * subprocess, the same way the current process loaded them.
 *
 * Swoole scenarios run in a subprocess because Swoole logs curl-hook
 * warnings to stderr when ext-curl is absent, and run-tests.php merges
 * stderr into the compared output.
 *
 * Resolution order: $DUCKDB_EXTENSION_PATH (set by tests/harness.php),
 * then the default extension dir, then a plain spawn that relies on
 * php.ini. Returns null when duckdb cannot be loaded by a subprocess.
 *
 * @return list<string>|null argv elements after the PHP binary
 */
function duckdb_swoole_spawn_args(): ?array
{
    $args = [];

    $extPath = getenv('DUCKDB_EXTENSION_PATH') ?: null;
    if ($extPath === null || !is_file($extPath)) {
        $candidate = PHP_EXTENSION_DIR . '/duckdb.so';
        $extPath = is_file($candidate) ? $candidate : null;
    }
    if ($extPath !== null) {
        $args = ['-d', 'extension=' . $extPath];
    } else {
        // Last resort: duckdb must be loadable from php.ini.
        $probe = proc_open(
            [PHP_BINARY, '-r', 'exit(extension_loaded("duckdb") ? 0 : 1);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $probePipes,
        );
        fclose($probePipes[0]);
        fclose($probePipes[1]);
        if (proc_close($probe) !== 0) {
            return null;
        }
    }

    $args[] = '-d';
    $args[] = 'swoole.hook_flags=0';
    return $args;
}
