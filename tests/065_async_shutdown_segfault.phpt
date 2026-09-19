--TEST--
Async: an abandoned queryAsync() worker cannot crash process shutdown
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip cannot locate duckdb.so for the subprocess');
}
?>
--FILE--
<?php
// Regression: the detached worker of a queryAsync() that is never awaited
// could still be in flight at module shutdown and execute code in the
// already dlclosed extension mapping (SIGSEGV, exit 139). MSHUTDOWN now
// interrupts and waits for all in-flight workers before the module may be
// unloaded. Run in subprocesses: a crash must not take this test down with
// it, and the child exit code is the assertion. The race is timing-based,
// so repeat it — pre-fix the child died in most runs.
require_once __DIR__ . '/subprocess.inc';

$childCode =
    '$db = new DuckDB\\Database(":memory:");'
    . '$db->connect()->queryAsync("SELECT 1");'
    . '/* abandoned: never awaited, script ends immediately */';

$crashed = 0;
for ($i = 0; $i < 5; $i++) {
    $proc = proc_open(
        array_merge([PHP_BINARY], duckdb_subprocess_args(), ['-r', $childCode]),
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    fclose($pipes[0]);
    $code = proc_close($proc);
    if ($code !== 0) {
        echo "run $i: subprocess exited with $code\n";
        $crashed++;
    }
}
echo $crashed === 0 ? "no crash\n" : "crashed\n";
?>
--EXPECT--
no crash
