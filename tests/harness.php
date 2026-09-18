#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * duckdb-php test harness.
 *
 * One entry point for every verification stage of the extension:
 *
 *   php tests/harness.php                # doctor + build + unit + examples
 *   php tests/harness.php --full         # ... plus valgrind + stress
 *   php tests/harness.php unit           # run only the .phpt suite
 *   php tests/harness.php build unit     # explicit stage selection
 *
 * Stages:
 *   doctor    Verify the toolchain and environment (PHP, phpize, libduckdb,
 *             run-tests.php, valgrind when needed). Fails with actionable
 *             messages instead of letting later stages explode.
 *   build     phpize + configure + make. Incremental: skipped when the
 *             module is newer than all sources, unless --rebuild is given.
 *   unit      The .phpt suite via PHP's run-tests.php, parallel (-j).
 *   examples  Every examples/*.php must exit 0 with the extension loaded.
 *   valgrind  The .phpt suite under Valgrind (run-tests.php -m): any
 *             definite leak or memory error fails the stage.
 *   stress    tests/stress/*.php: memory-stability and concurrency loops.
 *
 * Options:
 *   --quick              doctor + unit only
 *   --full               all six stages
 *   --jobs=N             parallel test workers (default: CPU count)
 *   --filter=PATTERN     only .phpt files matching PATTERN (fnmatch)
 *   --duckdb-dir=DIR     DuckDB install prefix (default: /opt/duckdb,
 *                        or $DUCKDB_DIR)
 *   --extension=PATH     test this .so instead of modules/duckdb.so
 *   --rebuild            force a clean build
 *   --junit=FILE         write a JUnit XML report
 *   --no-color           disable ANSI colors
 *   --help               show this text
 *
 * Exit codes: 0 = all stages passed, 1 = a stage failed,
 *             2 = environment problem, 3 = build failure, 64 = bad usage.
 */

namespace DuckDB\Test;

use RuntimeException;

final class Config
{
    /** @var list<string> */
    public array $stages = [];
    public bool $quick = false;
    public bool $full = false;
    public bool $rebuild = false;
    public bool $color = true;
    public ?string $filter = null;
    public ?string $junit = null;
    public string $duckdbDir;
    public ?string $extension = null;
    public int $jobs;
    public string $rootDir;

    public const ALL_STAGES = ['doctor', 'build', 'unit', 'examples', 'valgrind', 'stress'];
    public const KNOWN_STAGES = ['doctor', 'build', 'unit', 'examples', 'valgrind', 'stress'];

    public const EXIT_OK = 0;
    public const EXIT_STAGE_FAILED = 1;
    public const EXIT_ENV = 2;
    public const EXIT_BUILD = 3;
    public const EXIT_USAGE = 64;
}

final class HarnessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode = Config::EXIT_STAGE_FAILED,
    ) {
        parent::__construct($message);
    }
}

/**
 * A single external command, run with stdout+stderr captured together.
 */
final class Process
{
    public int $exitCode = -1;
    public string $output = '';

    /** @param array<string,string> $env */
    public function __construct(
        public readonly array $command,
        public readonly ?string $cwd = null,
        public readonly array $env = [],
    ) {
    }

    public function run(): self
    {
        $cmd = implode(' ', array_map('escapeshellarg', $this->command));
        $cmd .= ' 2>&1';

        $env = null;
        if ($this->env !== []) {
            // getenv() without arguments returns the full environment as
            // string=>string (unlike $_SERVER, which also holds argv/argc).
            $base = getenv();
            $env = array_merge(is_array($base) ? $base : [], $this->env);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->cwd, $env);
        if (!is_resource($proc)) {
            throw new HarnessException("Failed to spawn: {$cmd}", Config::EXIT_ENV);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->output = ($out === false ? '' : $out) . ($err === false ? '' : $err);
        $this->exitCode = proc_close($proc);
        return $this;
    }
}

final class StageResult
{
    /** @var list<array{name:string, ok:bool, detail:string}> individual cases for JUnit */
    public array $cases = [];

    public function __construct(
        public readonly string $name,
        public readonly bool $ok,
        public readonly string $detail,
        public readonly float $duration,
    ) {
    }
}

final class Terminal
{
    public function __construct(private readonly bool $color)
    {
    }

    public function headline(string $text): void
    {
        $this->line($this->paint("== {$text} ", '1;36') . $this->paint(str_repeat('=', max(0, 70 - strlen($text) - 4)), '36'));
    }

    public function ok(string $text): void
    {
        $this->line($this->paint('PASS', '1;32') . ' ' . $text);
    }

    public function fail(string $text): void
    {
        $this->line($this->paint('FAIL', '1;31') . ' ' . $text);
    }

    public function info(string $text): void
    {
        $this->line($this->paint('----', '33') . ' ' . $text);
    }

    public function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public function paint(string $text, string $code): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}

final class Harness
{
    private Terminal $term;
    /** @var list<StageResult> */
    private array $results = [];

    public function __construct(private readonly Config $config)
    {
        $this->term = new Terminal($config->color);
    }

    public function run(): int
    {
        $stages = $this->config->stages;
        foreach ($stages as $stage) {
            $this->term->headline($stage);
            $start = microtime(true);
            try {
                $result = match ($stage) {
                    'doctor'   => $this->stageDoctor(),
                    'build'    => $this->stageBuild(),
                    'unit'     => $this->stageUnit(false),
                    'examples' => $this->stageExamples(),
                    'valgrind' => $this->stageUnit(true),
                    'stress'   => $this->stageStress(),
                    default    => throw new HarnessException("Unknown stage: {$stage}", Config::EXIT_USAGE),
                };
            } catch (HarnessException $e) {
                $result = new StageResult($stage, false, $e->getMessage(), microtime(true) - $start);
                if ($e->exitCode !== Config::EXIT_STAGE_FAILED) {
                    // Environment / build / usage errors abort the whole run.
                    $this->results[] = $result;
                    $this->report();
                    return $e->exitCode;
                }
            }
            $this->results[] = $result;

            $elapsed = number_format($result->duration, 1);
            if ($result->ok) {
                $this->term->ok("{$stage} ({$elapsed}s) — {$result->detail}");
            } else {
                $this->term->fail("{$stage} ({$elapsed}s) — {$result->detail}");
            }
        }

        return $this->report();
    }

    private function report(): int
    {
        $this->term->headline('summary');
        $failed = 0;
        $total = number_format(array_sum(array_map(static fn (StageResult $r): float => $r->duration, $this->results)), 1);
        foreach ($this->results as $r) {
            if ($r->ok) {
                $this->term->ok(sprintf('%-10s %6.1fs  %s', $r->name, $r->duration, $r->detail));
            } else {
                $this->term->fail(sprintf('%-10s %6.1fs  %s', $r->name, $r->duration, $r->detail));
                $failed++;
            }
        }
        $this->term->line("Total: {$total}s");

        if ($this->config->junit !== null) {
            $this->writeJunit($this->config->junit);
            $this->term->info("JUnit report written to {$this->config->junit}");
        }

        return $failed === 0 ? Config::EXIT_OK : Config::EXIT_STAGE_FAILED;
    }

    // ---------------------------------------------------------------- doctor

    private function stageDoctor(): StageResult
    {
        $start = microtime(true);
        $problems = [];

        if (PHP_VERSION_ID < 80200) {
            $problems[] = 'PHP >= 8.2 required, found ' . PHP_VERSION;
        }

        $root = $this->config->rootDir;
        foreach (['config.m4', 'duckdb.cpp', 'duckdb.stub.php'] as $f) {
            if (!is_file("{$root}/{$f}")) {
                $problems[] = "{$f} not found — run the harness from the project root";
            }
        }

        if ($this->which('phpize') === null) {
            $problems[] = 'phpize not found on PATH (install the PHP development package, e.g. php-dev)';
        }

        $duckdb = $this->config->duckdbDir;
        if (!is_file("{$duckdb}/include/duckdb.h")) {
            $problems[] = "duckdb.h not found under {$duckdb}/include — pass --duckdb-dir=DIR or set \$DUCKDB_DIR";
        }
        if (glob("{$duckdb}/lib/libduckdb.*") === [] && glob("{$duckdb}/libduckdb.*") === []) {
            $problems[] = "libduckdb not found under {$duckdb}/lib";
        }

        if ($this->runTestsPath() === null) {
            $problems[] = 'run-tests.php not found — set PHP_RUNTESTS or install the php-dev package';
        }

        $needsValgrind = in_array('valgrind', $this->config->stages, true);
        if ($needsValgrind && $this->which('valgrind') === null) {
            $problems[] = 'valgrind stage requested but valgrind is not installed';
        }

        if ($problems !== []) {
            foreach ($problems as $p) {
                $this->term->fail($p);
            }
            throw new HarnessException(count($problems) . ' environment problem(s) — see above', Config::EXIT_ENV);
        }

        $detail = sprintf(
            'PHP %s, libduckdb at %s, run-tests.php at %s',
            PHP_VERSION,
            $duckdb,
            $this->runTestsPath(),
        );
        $this->term->info($detail);
        return new StageResult('doctor', true, $detail, microtime(true) - $start);
    }

    // ---------------------------------------------------------------- build

    private function stageBuild(): StageResult
    {
        $start = microtime(true);
        $root = $this->config->rootDir;
        $module = $this->modulePath();

        if (!$this->config->rebuild && $module !== null && !$this->sourcesNewerThan($module)) {
            return new StageResult('build', true, "up to date ({$module})", microtime(true) - $start);
        }

        $env = [];
        if ($this->config->rebuild && is_file("{$root}/Makefile")) {
            (new Process(['make', 'clean'], $root))->run();
        }

        // phpize is idempotent; run it only when configure is missing/stale.
        if (!is_file("{$root}/configure") || filemtime("{$root}/configure") < filemtime("{$root}/config.m4")) {
            $phpize = (new Process(['phpize'], $root))->run();
            if ($phpize->exitCode !== 0) {
                $this->term->line($phpize->output);
                throw new HarnessException('phpize failed', Config::EXIT_BUILD);
            }
        }

        $configure = (new Process(
            ['sh', './configure', '--with-duckdb=' . $this->config->duckdbDir],
            $root,
            $env,
        ))->run();
        if ($configure->exitCode !== 0) {
            $this->term->line($configure->output);
            throw new HarnessException('configure failed', Config::EXIT_BUILD);
        }

        $jobs = $this->config->jobs;
        $make = (new Process(['make', "-j{$jobs}"], $root, $env))->run();
        if ($make->exitCode !== 0 || $this->modulePath() === null) {
            $this->term->line($make->output);
            throw new HarnessException('make failed', Config::EXIT_BUILD);
        }

        return new StageResult('build', true, 'built ' . $this->modulePath(), microtime(true) - $start);
    }

    // ---------------------------------------------------------------- unit / valgrind

    private function stageUnit(bool $valgrind): StageResult
    {
        $start = microtime(true);
        $name = $valgrind ? 'valgrind' : 'unit';
        $root = $this->config->rootDir;

        $runTests = $this->runTestsPath();
        if ($runTests === null) {
            throw new HarnessException('run-tests.php not found (run the doctor stage)', Config::EXIT_ENV);
        }
        $runTests = $this->writableRunTests($runTests);
        $module = $this->modulePath();
        if ($module === null) {
            throw new HarnessException('extension not built — run the build stage first', Config::EXIT_BUILD);
        }

        $tests = $this->collectTests("{$root}/tests", $this->config->filter);
        if ($tests === []) {
            throw new HarnessException('no tests matched', Config::EXIT_USAGE);
        }
        if ($valgrind) {
            // Tests containing a --VALGRIND-SKIP-- line are excluded here:
            // they are slow-path stress tests whose runtime under Memcheck
            // exceeds any sane per-test timeout, and whose memory behavior
            // is already covered by the stress stage under Valgrind.
            $before = count($tests);
            $tests = array_values(array_filter($tests, static function (string $t): bool {
                $contents = file_get_contents($t);
                return $contents === false || !str_contains($contents, '--VALGRIND-SKIP--');
            }));
            $excluded = $before - count($tests);
            if ($excluded > 0) {
                $this->term->info("{$excluded} test(s) marked --VALGRIND-SKIP-- excluded from Memcheck run");
            }
        }

        $args = [
            PHP_BINARY,
            $runTests,
            '-q',
            "-j{$this->config->jobs}",
            '-P',
            '-d', "extension={$module}",
        ];
        if ($valgrind) {
            $args[] = '-m';
            $args[] = '--set-timeout';
            $args[] = '600';
        }
        $args = array_merge($args, $tests);

        $env = $this->childEnv();
        if ($valgrind) {
            // Zend's allocator hides leaks from Valgrind; disable it.
            $env['USE_ZEND_ALLOC'] = '0';
            $supp = "{$root}/tests/duckdb.supp";
            $env['VALGRIND_OPTS'] = '--error-exitcode=99 --errors-for-leak-kinds=definite --leak-check=full --num-callers=30'
                . (is_file($supp) ? " --suppressions={$supp}" : '');
        }

        $proc = (new Process($args, $root, $env + ['DUCKDB_EXTENSION_PATH' => $module]))->run();
        $summary = $this->parseRunTestsSummary($proc->output);

        if ($summary === null) {
            $this->term->line($proc->output);
            throw new HarnessException('run-tests.php produced no summary', Config::EXIT_ENV);
        }

        [$passed, $failed, $leaked, $warned, $skipped] = $summary;
        $cases = $this->collectCases("{$root}/tests", $tests, $proc->output, $name);

        $bad = $failed + $leaked;
        $detail = sprintf(
            '%d passed, %d failed%s%s, %d skipped (%d tests)',
            $passed,
            $failed,
            $leaked > 0 ? ", {$leaked} leaked" : '',
            $warned > 0 ? ", {$warned} warned" : '',
            $skipped,
            $passed + $failed + $leaked + $warned + $skipped,
        );

        if ($bad > 0) {
            $this->printFailedTestDetails("{$root}/tests", $proc->output);
        }

        $result = new StageResult($name, $bad === 0, $detail, microtime(true) - $start);
        $result->cases = $cases;
        return $result;
    }

    // ---------------------------------------------------------------- examples / stress

    private function stageExamples(): StageResult
    {
        return $this->runScriptDirectory('examples', $this->config->rootDir . '/examples');
    }

    private function stageStress(): StageResult
    {
        return $this->runScriptDirectory('stress', $this->config->rootDir . '/tests/stress');
    }

    /** Run every *.php in a directory with the extension loaded; all must exit 0. */
    private function runScriptDirectory(string $stage, string $dir): StageResult
    {
        $start = microtime(true);
        $module = $this->modulePath();
        if ($module === null) {
            throw new HarnessException('extension not built — run the build stage first', Config::EXIT_BUILD);
        }
        if (!is_dir($dir)) {
            throw new HarnessException("directory not found: {$dir}", Config::EXIT_USAGE);
        }

        $scripts = glob("{$dir}/*.php") ?: [];
        if ($scripts === []) {
            throw new HarnessException("no scripts in {$dir}", Config::EXIT_USAGE);
        }
        sort($scripts);

        $cases = [];
        $failures = 0;
        foreach ($scripts as $script) {
            $base = basename($script);
            $proc = (new Process(
                [PHP_BINARY, '-d', "extension={$module}", $script],
                $this->config->rootDir,
                $this->childEnv(),
            ))->run();

            $ok = $proc->exitCode === 0;
            $cases[] = ['name' => $base, 'ok' => $ok, 'detail' => ''];
            if ($ok) {
                $this->term->ok($base);
            } else {
                $failures++;
                $this->term->fail("{$base} (exit {$proc->exitCode})");
                $tail = implode(PHP_EOL, array_slice(explode(PHP_EOL, trim($proc->output)), -10));
                $this->term->line($tail);
            }
        }

        $detail = sprintf('%d/%d scripts passed', count($scripts) - $failures, count($scripts));
        $result = new StageResult($stage, $failures === 0, $detail, microtime(true) - $start);
        $result->cases = $cases;
        return $result;
    }

    // ---------------------------------------------------------------- helpers

    private function modulePath(): ?string
    {
        if ($this->config->extension !== null) {
            return is_file($this->config->extension) ? $this->config->extension : null;
        }
        $module = $this->config->rootDir . '/modules/duckdb.so';
        return is_file($module) ? $module : null;
    }

    private function sourcesNewerThan(string $artifact): bool
    {
        $stamp = filemtime($artifact);
        $root = $this->config->rootDir;
        $sources = array_merge(
            glob("{$root}/*.cpp") ?: [],
            glob("{$root}/*.h") ?: [],
            glob("{$root}/src/*.cpp") ?: [],
            glob("{$root}/src/*.h") ?: [],
            ["{$root}/config.m4"],
        );
        foreach ($sources as $src) {
            if (is_file($src) && filemtime($src) > $stamp) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> absolute paths of .phpt files */
    private function collectTests(string $dir, ?string $filter): array
    {
        $all = glob("{$dir}/*.phpt") ?: [];
        sort($all);
        if ($filter === null) {
            return $all;
        }
        return array_values(array_filter(
            $all,
            static fn (string $t): bool => fnmatch($filter, basename($t), FNM_CASEFOLD),
        ));
    }

    /**
     * Parse run-tests.php's closing summary.
     *
     * @return ?array{0:int,1:int,2:int,3:int,4:int} [passed, failed, leaked, warned, skipped]
     */
    private function parseRunTestsSummary(string $output): ?array
    {
        $grab = static function (string $label) use ($output): int {
            return preg_match("/^{$label}\\s*:\\s*(\\d+)/m", $output, $m) === 1 ? (int) $m[1] : 0;
        };
        if (preg_match('/^Number of tests\s*:\s*(\d+)/m', $output) !== 1) {
            return null;
        }
        return [
            $grab('Tests passed'),
            $grab('Tests failed'),
            $grab('Tests leaked') + $grab('Exts leaked'),
            $grab('Tests warned'),
            $grab('Tests skipped'),
        ];
    }

    /**
     * Derive per-test JUnit cases. run-tests.php's parallel progress output is
     * carriage-return based and fragile to parse, so we derive case results
     * from the summary plus the FAILED/LEAKED test lists printed at the end.
     *
     * @param list<string> $tests
     * @return list<array{name:string, ok:bool, detail:string}>
     */
    private function collectCases(string $testsDir, array $tests, string $output, string $prefix): array
    {
        $bad = [];
        foreach (explode(PHP_EOL, $output) as $line) {
            if (preg_match('/\[(tests\/[^\]]+\.phpt)\]/', $line, $m) === 1
                && (str_starts_with(trim($line), 'FAIL') || str_contains($line, ' LEAK '))) {
                $bad[basename($m[1])] = true;
            }
        }
        // run-tests also lists failures in the "FAILED TEST SUMMARY" block.
        if (preg_match('/=+\nFAILED TEST SUMMARY\n-+\n(.*?)\n=+/s', $output, $m) === 1) {
            foreach (explode(PHP_EOL, $m[1]) as $line) {
                if (preg_match('/\[(tests\/[^\]]+\.phpt)\]/', $line, $tm) === 1) {
                    $bad[basename($tm[1])] = true;
                }
            }
        }

        $cases = [];
        foreach ($tests as $t) {
            $base = basename($t);
            $cases[] = ['name' => "{$prefix}/{$base}", 'ok' => !isset($bad[$base]), 'detail' => ''];
        }
        return $cases;
    }

    /** Print the FAILED TEST SUMMARY block (and .diff pointers) for humans. */
    private function printFailedTestDetails(string $testsDir, string $output): void
    {
        if (preg_match('/=+\n(FAILED TEST SUMMARY\n.*?)\n=+/s', $output, $m) === 1) {
            $this->term->line('');
            $this->term->line($m[1]);
            $this->term->line('');
        }
        $this->term->info('see *.diff / *.out / *.mem next to the failing tests for details');
    }

    /** @return array<string,string> environment for child PHP processes */
    private function childEnv(): array
    {
        $libDir = $this->config->duckdbDir . '/lib';
        $env = [];
        foreach (['LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH'] as $var) {
            $current = getenv($var) ?: '';
            $env[$var] = $current === '' ? $libDir : $libDir . PATH_SEPARATOR . $current;
        }
        return $env;
    }

    private function runTestsPath(): ?string
    {
        $fromEnv = getenv('PHP_RUNTESTS');
        if ($fromEnv !== false && is_file($fromEnv)) {
            return $fromEnv;
        }
        // Ask php-config where the build tree lives — the authoritative
        // answer for the PHP binary actually running this harness (matters
        // when several PHP builds coexist, e.g. a True Async source install
        // in /usr/local next to a distro PHP). Prefer the php-config that
        // sits next to the running interpreter over whatever PATH finds.
        $candidates = [];
        $phpConfigs = [dirname(PHP_BINARY) . '/php-config', $this->which('php-config')];
        foreach (array_unique(array_filter($phpConfigs)) as $phpConfig) {
            if (!is_executable($phpConfig)) {
                continue;
            }
            $prefix = trim((new Process([$phpConfig, '--prefix']))->run()->output);
            $includeDir = trim((new Process([$phpConfig, '--include-dir']))->run()->output);
            $extensionDir = trim((new Process([$phpConfig, '--extension-dir']))->run()->output);
            if ($prefix !== '') {
                $candidates[] = "{$prefix}/lib/php/build/run-tests.php";
            }
            if ($includeDir !== '') {
                $candidates[] = "{$includeDir}/build/run-tests.php";
            }
            if ($extensionDir !== '') {
                // Debian/Ubuntu: /usr/lib/php/<zend-api>/{extension-dir,build}
                $candidates[] = "{$extensionDir}/build/run-tests.php";
            }
        }
        $candidates[] = '/usr/lib/php/build/run-tests.php';
        // Debian/Ubuntu php-dev installs to /usr/lib/php/<zend-api>/build/.
        foreach (glob('/usr/lib/php/*/build/run-tests.php') ?: [] as $path) {
            $candidates[] = $path;
        }
        foreach (array_unique($candidates) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return $this->fetchRunTests();
    }

    /**
     * CI images (e.g. shivammathur/setup-php) ship PHP without run-tests.php.
     * Fetch the exact version's copy from php-src and cache it.
     */
    private function fetchRunTests(): ?string
    {
        $url = sprintf(
            'https://raw.githubusercontent.com/php/php-src/php-%s/run-tests.php',
            PHP_VERSION,
        );
        $cache = sys_get_temp_dir() . '/run-tests-' . PHP_VERSION . '.php';
        if (is_file($cache) && filemtime($cache) > time() - 86400 * 30) {
            return $cache;
        }
        $this->term->info("run-tests.php not installed locally; fetching {$url}");
        $contents = @file_get_contents($url);
        if ($contents === false && $this->which('curl') !== null) {
            $proc = (new Process(['curl', '-fsSL', $url, '-o', $cache]))->run();
            if ($proc->exitCode === 0 && is_file($cache)) {
                return $cache;
            }
        } elseif ($contents !== false && file_put_contents($cache, $contents) !== false) {
            return $cache;
        }
        return null;
    }

    /**
     * run-tests.php writes run-test-info.php next to itself, so a copy
     * installed system-wide (e.g. /usr/lib/php/<api>/build/) fails for
     * unprivileged users with "Cannot open file ... (save_text)". Use a
     * writable copy in the temp dir in that case.
     */
    private function writableRunTests(string $runTests): string
    {
        if (is_writable(dirname($runTests))) {
            return $runTests;
        }
        $copy = sys_get_temp_dir() . '/run-tests-' . PHP_VERSION . '.php';
        if (!is_file($copy) || filemtime($copy) < filemtime($runTests)) {
            if (!@copy($runTests, $copy)) {
                throw new HarnessException(
                    "run-tests.php ({$runTests}) sits in a non-writable directory and could not be copied",
                    Config::EXIT_ENV,
                );
            }
        }
        return $copy;
    }

    private function which(string $binary): ?string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $dir) {
            if (is_file("{$dir}/{$binary}") && is_executable("{$dir}/{$binary}")) {
                return "{$dir}/{$binary}";
            }
        }
        return null;
    }

    private function writeJunit(string $file): void
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $suites = $doc->createElement('testsuites');
        $doc->appendChild($suites);

        foreach ($this->results as $stage) {
            $cases = $stage->cases !== [] ? $stage->cases : [
                ['name' => $stage->name, 'ok' => $stage->ok, 'detail' => $stage->detail],
            ];
            $failures = count(array_filter($cases, static fn (array $c): bool => !$c['ok']));

            $suite = $doc->createElement('testsuite');
            $suite->setAttribute('name', $stage->name);
            $suite->setAttribute('tests', (string) count($cases));
            $suite->setAttribute('failures', (string) $failures);
            $suite->setAttribute('time', number_format($stage->duration, 3, '.', ''));
            $suites->appendChild($suite);

            foreach ($cases as $case) {
                $tc = $doc->createElement('testcase');
                $tc->setAttribute('name', $case['name']);
                $tc->setAttribute('classname', "duckdb.{$stage->name}");
                $tc->setAttribute('time', '0');
                if (!$case['ok']) {
                    $failure = $doc->createElement('failure');
                    $failure->setAttribute('message', $case['detail'] !== '' ? $case['detail'] : "{$case['name']} failed");
                    $tc->appendChild($failure);
                }
                $suite->appendChild($tc);
            }
        }

        if ($doc->save($file) === false) {
            throw new HarnessException("cannot write JUnit report to {$file}", Config::EXIT_ENV);
        }
    }
}

// ------------------------------------------------------------------ CLI

function usage(): string
{
    return <<<'TXT'
duckdb-php test harness

Usage: php tests/harness.php [STAGE ...] [OPTION ...]

Stages (default: doctor build unit examples):
  doctor     verify toolchain: PHP, phpize, libduckdb, run-tests.php, valgrind
  build      phpize + configure + make (incremental; --rebuild forces clean)
  unit       run the .phpt suite (parallel)
  examples   run every examples/*.php, all must exit 0
  valgrind   run the .phpt suite under Valgrind (definite leaks = failure)
  stress     run tests/stress/*.php (memory stability, concurrency)

Options:
  --quick              doctor + unit only
  --full               all six stages
  --jobs=N             parallel test workers (default: CPU count)
  --filter=PATTERN     only .phpt files matching PATTERN (fnmatch, case-insensitive)
  --duckdb-dir=DIR     DuckDB install prefix (default: $DUCKDB_DIR or /opt/duckdb)
  --extension=PATH     test this .so instead of modules/duckdb.so
  --rebuild            force a clean rebuild
  --junit=FILE         write a JUnit XML report
  --no-color           disable ANSI colors
  --help               show this text

Exit codes: 0 ok, 1 stage failed, 2 environment problem, 3 build failure, 64 usage
TXT;
}

/** @param list<string> $argv */
function parseArgs(array $argv): Config
{
    $config = new Config();
    $config->rootDir = dirname(__DIR__);
    $config->duckdbDir = getenv('DUCKDB_DIR') ?: '/opt/duckdb';
    $nproc = trim((string) @shell_exec('nproc 2>/dev/null'));
    $config->jobs = preg_match('/^\d+$/', $nproc) === 1 && (int) $nproc > 0 ? (int) $nproc : 1;
    $config->color = function_exists('posix_isatty') && @posix_isatty(STDOUT);

    $explicitStages = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (in_array($arg, Config::KNOWN_STAGES, true)) {
            $explicitStages[] = $arg;
        } elseif ($arg === '--quick') {
            $config->quick = true;
        } elseif ($arg === '--full') {
            $config->full = true;
        } elseif ($arg === '--rebuild') {
            $config->rebuild = true;
        } elseif ($arg === '--no-color') {
            $config->color = false;
        } elseif (str_starts_with($arg, '--jobs=')) {
            $config->jobs = max(1, (int) substr($arg, 7));
        } elseif (str_starts_with($arg, '--filter=')) {
            $config->filter = substr($arg, 9);
        } elseif (str_starts_with($arg, '--duckdb-dir=')) {
            $config->duckdbDir = rtrim(substr($arg, 13), '/');
        } elseif (str_starts_with($arg, '--extension=')) {
            $config->extension = substr($arg, 12);
        } elseif (str_starts_with($arg, '--junit=')) {
            $config->junit = substr($arg, 8);
        } elseif ($arg === '--help' || $arg === '-h') {
            echo usage(), PHP_EOL;
            exit(Config::EXIT_OK);
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}" . PHP_EOL . PHP_EOL . usage() . PHP_EOL);
            exit(Config::EXIT_USAGE);
        }
    }

    if ($config->quick && $config->full) {
        fwrite(STDERR, '--quick and --full are mutually exclusive' . PHP_EOL);
        exit(Config::EXIT_USAGE);
    }

    if ($explicitStages !== []) {
        // Keep canonical stage order regardless of CLI order.
        $config->stages = array_values(array_intersect(Config::ALL_STAGES, $explicitStages));
    } elseif ($config->quick) {
        $config->stages = ['doctor', 'unit'];
    } elseif ($config->full) {
        $config->stages = Config::ALL_STAGES;
    } else {
        $config->stages = ['doctor', 'build', 'unit', 'examples'];
    }

    return $config;
}

// PHP on Linux: detect CPUs via nproc fallback chain handled in parseArgs.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'The harness must run under the CLI SAPI' . PHP_EOL);
    exit(Config::EXIT_USAGE);
}

exit((new Harness(parseArgs($argv)))->run());
