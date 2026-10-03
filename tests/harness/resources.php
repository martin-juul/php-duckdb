<?php
/** Standalone worker-selection regression: php tests/harness/resources.php */
declare(strict_types=1);

$root = sys_get_temp_dir() . '/duckdb-harness-resources-' . bin2hex(random_bytes(6));
foreach (['tests', 'modules', 'bin', 'packaging/resources'] as $directory) {
    mkdir($root . '/' . $directory, 0777, true);
}
copy(dirname(__DIR__) . '/harness.php', $root . '/tests/harness.php');
file_put_contents($root . '/tests/fixture.phpt', "--TEST--\nfixture\n");
file_put_contents($root . '/config.m4', 'fixture');
file_put_contents($root . '/configure', "#!/bin/sh\nexit 0\n");
touch($root . '/configure', time() + 1);
file_put_contents($root . '/bin/make', <<<'SH'
#!/bin/sh
printf fixture > modules/duckdb.so
printf '%s\n' "$1" > make-workers.txt
SH);
chmod($root . '/bin/make', 0755);
symlink('/bin/sh', $root . '/bin/sh');
file_put_contents($root . '/packaging/resources/jobs.py', 'fixture');
file_put_contents($root . '/bin/python3', <<<'SH'
#!/bin/sh
case "$3" in
extension) printf '2\n';;
test) printf '3\n';;
valgrind) printf '4\n';;
*) exit 1;;
esac
SH);
chmod($root . '/bin/python3', 0755);
file_put_contents($root . '/run-tests.php', <<<'PHP'
<?php
foreach ($argv as $argument) {
    if (str_starts_with($argument, '-j')) {
        file_put_contents(__DIR__ . '/runner-workers.txt', substr($argument, 2) . "\n", FILE_APPEND);
    }
}
echo "Number of tests : 1\nTests passed : 1\nTests failed : 0\nTests skipped : 0\n";
PHP);

function runFixture(string $root, array $arguments, array $changes = []): array
{
    $environment = getenv();
    unset($environment['DUCKDB_JOBS']);
    $environment = array_merge($environment, [
        'PATH' => $root . '/bin',
        'PHP_RUNTESTS' => $root . '/run-tests.php',
        'TMPDIR' => $root,
    ], $changes);
    $command = array_merge([PHP_BINARY, $root . '/tests/harness.php', '--no-color'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch worker-selection fixture');
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

function expectFixture(array $result, int $status, string $message): void
{
    if ($result[0] !== $status || !str_contains($result[1], $message)) {
        throw new RuntimeException("Unexpected fixture result: {$result[0]}\n{$result[1]}");
    }
}

try {
    // These fake commands build no native code and never use the shared module.
    $result = runFixture($root, ['build', 'unit', 'valgrind']);
    foreach ([
        '2 (extension; CPU/memory budget)',
        '3 (test; CPU/memory budget)',
        '4 (valgrind; CPU/memory budget)',
    ] as $message) {
        expectFixture($result, 0, $message);
    }
    if (file_get_contents($root . '/runner-workers.txt') !== "3\n4\n") {
        throw new RuntimeException('Runner did not receive test/valgrind-profile workers');
    }
    if (trim(file_get_contents($root . '/make-workers.txt')) !== '-j2') {
        throw new RuntimeException('Build did not receive extension-profile workers');
    }
    foreach (['unit', 'valgrind'] as $stage) {
        $result = runFixture($root, [$stage, '--jobs=7'], ['DUCKDB_JOBS' => 'invalid']);
        expectFixture($result, 0, 'Workers: 7');
        if (!str_ends_with(file_get_contents($root . '/runner-workers.txt'), "7\n")) {
            throw new RuntimeException('CLI worker override did not reach runner');
        }
        $result = runFixture($root, [$stage], ['DUCKDB_JOBS' => '5']);
        expectFixture($result, 0, 'Workers: 5');
        if (!str_ends_with(file_get_contents($root . '/runner-workers.txt'), "5\n")) {
            throw new RuntimeException('Environment worker override did not reach runner');
        }
    }
    foreach (['0', '00', '01', '-1', '1.5', 'garbage', '', '999999999999999999999999'] as $invalid) {
        expectFixture(runFixture($root, ['unit', '--jobs=' . $invalid]), 64, '--jobs requires a positive integer');
        if ($invalid !== '') {
            expectFixture(
                runFixture($root, ['unit'], ['DUCKDB_JOBS' => $invalid]),
                64,
                'DUCKDB_JOBS requires a positive integer'
            );
        }
    }
    expectFixture(runFixture($root, ['unit'], ['DUCKDB_JOBS' => '']), 0, 'Workers: 3');
    expectFixture(runFixture($root, ['unit'], ['PATH' => '']), 0, 'Workers: 1 (test; Python unavailable)');
    unlink($root . '/packaging/resources/jobs.py');
    expectFixture(runFixture($root, ['unit']), 0, 'Workers: 1 (test; resource helper unavailable)');
    file_put_contents($root . '/packaging/resources/jobs.py', 'fixture');
    file_put_contents($root . '/bin/python3', "#!/bin/sh\nprintf 'invalid\\n'\n");
    expectFixture(runFixture($root, ['valgrind']), 0, 'Workers: 1 (valgrind; resource probe failed)');
    echo "PASS harness resources: stage profiles, overrides, invalid input and safe fallbacks\n";
} finally {
    $remove = static function (string $directory) use (&$remove): void {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $entry->isDir() ? $remove($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    };
    $remove($root);
}
