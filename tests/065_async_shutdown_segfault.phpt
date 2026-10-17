--TEST--
Async: query and prepared workers finish before module shutdown
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip cannot locate the duckdb extension for the subprocess');
}
?>
--FILE--
<?php
// Worker completion precedes C++ and libc thread-local destruction. The
// module must join workers before unloading, including workers whose PHP
// handles were abandoned or whose query results have already been awaited.
// Repeat subprocesses because premature unloading is a timing-dependent race.
require_once __DIR__ . '/subprocess.inc';

$setup = '$c = (new DuckDB\\Database(":memory:", ["threads" => 1]))->connect();';
$childCodes = [
    $setup . '$c->queryAsync("SELECT 1");',
    $setup . '$c->prepare("SELECT 1")->executeAsync();',
    $setup
        . '$s = $c->prepare("SELECT 1");'
        . 'for ($i = 0; $i < 32; $i++) {'
        . '$c->queryAsync("SELECT 1")->await();'
        . '$s->executeAsync()->await();'
        . '}',
];

$crashed = 0;
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
foreach ($childCodes as $case => $childCode) {
    for ($i = 0; $i < 5; $i++) {
        $proc = proc_open(
            array_merge([PHP_BINARY], duckdb_subprocess_args(), ['-r', $childCode]),
            [0 => ['pipe', 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']],
            $pipes,
        );
        fclose($pipes[0]);
        $code = proc_close($proc);
        if ($code !== 0) {
            echo "case $case, run $i: subprocess exited with $code\n";
            $crashed++;
        }
    }
}
echo $crashed === 0 ? "no crash\n" : "crashed\n";
?>
--EXPECT--
no crash
