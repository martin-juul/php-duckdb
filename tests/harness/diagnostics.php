<?php

/** Standalone harness regression: php tests/harness/diagnostics.php */
declare(strict_types=1);

$fixtureRoot = sys_get_temp_dir() . '/duckdb-harness-diagnostics-' . bin2hex(random_bytes(6));
mkdir($fixtureRoot . '/tests', 0777, true);
copy(dirname(__DIR__) . '/harness.php', $fixtureRoot . '/tests/harness.php');
file_put_contents($fixtureRoot . '/duckdb.so', 'fixture');
file_put_contents($fixtureRoot . '/run-tests.php', <<<'PHP'
<?php
$options = explode(' ', getenv('VALGRIND_OPTS') ?: '');
foreach (['--fair-sched=try', '--error-exitcode=99', '--errors-for-leak-kinds=definite', '--leak-check=full'] as $option) {
    if (!in_array($option, $options, true)) {
        throw new RuntimeException("Missing Valgrind option: $option");
    }
}
if (getenv('USE_ZEND_ALLOC') !== '0') {
    throw new RuntimeException('Zend allocator must be disabled for Memcheck');
}
echo file_get_contents(__DIR__ . '/runner-output.txt');
exit((int) getenv('FIXTURE_EXIT'));
PHP);

$scenarios = [
    'mixed' => [['pass', 'fail', 'leak', 'warn', 'bork'], 1, 1],
    'warning only' => [['pass', 'warn'], 0, 1],
    'passed on retry' => [['pass', 'warn'], 0, 1],
    'bork only' => [['pass', 'bork'], 0, 1],
    'runner failure' => [['pass'], 17, 1],
    'missing summary' => [[], 17, 2],
    'success' => [['pass'], 0, 0],
];
$labels = ['fail' => 'FAILED', 'leak' => 'LEAKED', 'warn' => 'WARNED', 'bork' => 'BORKED'];
foreach ($scenarios as $scenario => [$names, $runnerExit, $expectedExit]) {
    foreach (glob($fixtureRoot . '/tests/*.phpt') ?: [] as $file) {
        unlink($file);
    }
    // Keep one selected file even for the runner-without-summary fixture.
    foreach ($names ?: ['pass'] as $name) {
        file_put_contents($fixtureRoot . '/tests/' . $name . '.phpt', "--TEST--\n$name\n");
    }
    $output = "Number of tests : " . count($names) . "\n";
    foreach ([
        'passed' => 'pass',
        'failed' => 'fail',
        'leaked' => 'leak',
        'warned' => 'warn',
        'borked' => 'bork'
    ] as $label => $name) {
        $output .= "Tests $label : " . (int) in_array($name, $names, true) . "\n";
    }
    $output .= "Tests skipped : 0\n";
    foreach ($labels as $name => $label) {
        if (in_array($name, $names, true)) {
            // BORKED summaries use absolute paths in PHP's real runner.
            $path = $name === 'bork' ? "$fixtureRoot/tests/$name.phpt" : "tests/$name.phpt";
            $output .= "=====================================================================\n$label TEST SUMMARY\n"
                . "---------------------------------------------------------------------\n$name diagnostic [$path]\n"
                . "=====================================================================\n\n";
        }
    }
    if ($scenario === 'missing summary') {
        $output = "runner stopped before producing a summary\n";
    }
    if ($scenario === 'passed on retry') {
        $output = "first attempt output: timeout while fetching rows\n"
            . "WARN fixture passed on retry [tests/warn.phpt]\n" . $output;
    }
    foreach ([false, true] as $crlf) {
        $raw = $crlf ? str_replace("\n", "\r\n", $output) : $output;
        file_put_contents($fixtureRoot . '/runner-output.txt', $raw);
        $report = $fixtureRoot . '/report.xml';
        @unlink($report);
        $command = [
            PHP_BINARY,
            $fixtureRoot . '/tests/harness.php',
            '--valgrind',
            '--no-color',
            '--jobs=1',
            '--extension=' . $fixtureRoot . '/duckdb.so',
            '--junit=' . $report
        ];
        $env = array_merge(getenv(), [
            'PHP_RUNTESTS' => $fixtureRoot . '/run-tests.php',
            'TMPDIR' => $fixtureRoot,
            'FIXTURE_EXIT' => (string) $runnerExit
        ]);
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $fixtureRoot,
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot launch fixture harness');
        }
        $console = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== $expectedExit) {
            throw new RuntimeException("$scenario: unexpected exit $status\n$console\n$errors");
        }
        if ($scenario !== 'missing summary' && !str_contains($console, '(' . count($names) . ' tests)')) {
            throw new RuntimeException("$scenario: incorrect total test count\n$console");
        }
        foreach (['warn' => 'warned', 'bork' => 'borked'] as $name => $label) {
            if (in_array($name, $names, true) && !str_contains($console, "1 $label")) {
                throw new RuntimeException("$scenario: omitted $label count\n$console");
            }
        }
        foreach ($labels as $name => $label) {
            if (in_array($name, $names, true) && !str_contains($console, "$label TEST SUMMARY")) {
                throw new RuntimeException("$scenario: omitted $label summary\n$console");
            }
        }
        $xml = new DOMDocument();
        if (!$xml->load($report)) {
            throw new RuntimeException("$scenario: missing JUnit report");
        }
        $xpath = new DOMXPath($xml);
        $expectedFailures = count(array_intersect(array_keys($labels), $names));
        if ($scenario === 'runner failure' || $scenario === 'missing summary') {
            ++$expectedFailures;
        }
        if ((int) $xpath->evaluate('string(//testsuite/@failures)') !== $expectedFailures) {
            throw new RuntimeException("$scenario: incorrect JUnit failure count\n" . $xml->saveXML());
        }
        foreach ($labels as $name => $label) {
            if (in_array($name, $names, true)
                && !str_contains($xpath->evaluate("string(//testcase[@name='valgrind/$name.phpt']/failure/@message)"), "$name diagnostic")) {
                throw new RuntimeException("$scenario: $name incorrectly reported as passing");
            }
        }
        if ($xpath->evaluate("count(//testcase[@name='valgrind/pass.phpt']/failure)") !== 0.0) {
            throw new RuntimeException("$scenario: passing fixture incorrectly failed");
        }
        $hasRawLog = preg_match('/run-tests output saved to (.+)/', $console, $match) === 1;
        if (($expectedExit !== 0) !== $hasRawLog) {
            throw new RuntimeException("$scenario: incorrect raw-log preservation\n$console");
        }
        if ($hasRawLog && file_get_contents(trim($match[1])) !== $raw) {
            throw new RuntimeException("$scenario: saved output differs from runner output");
        }
        if ($hasRawLog) {
            // tempnam() resolves symlinks such as macOS /var -> /private/var.
            $saved = realpath(trim($match[1]));
            $uploadFiles = array_map('realpath', glob($fixtureRoot . '/duckdb-*-run-tests-*') ?: []);
            if (!in_array($saved, $uploadFiles, true)) {
                throw new RuntimeException("$scenario: raw output does not match CI upload pattern");
            }
        }
    }
}

$remove = static function (string $dir) use (&$remove): void {
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        $entry->isDir() ? $remove($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
};
$remove($fixtureRoot);
echo "PASS harness diagnostics: seven scenarios, LF/CRLF, console, exit status, JUnit and uploaded raw logs\n";
