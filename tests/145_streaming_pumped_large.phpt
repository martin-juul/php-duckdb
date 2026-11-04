--TEST--
Pumped streaming: PHP callbacks keep running while a stream is fetched, on every fetch path and at any size
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (duckdb_subprocess_args() === null) {
    die('skip duckdb cannot be loaded by a subprocess');
}
if (!function_exists('proc_open')) {
    die('skip proc_open is unavailable');
}
?>
--FILE--
<?php
require __DIR__ . '/copy.inc';
require_once __DIR__ . '/subprocess.inc';

final class CountingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public int $calls = 0;
    public int $rows = 0;
    public ?Closure $hook = null;

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        $this->calls++;
        $this->rows += $arguments->rowCount();
        if ($this->hook !== null) {
            ($this->hook)($this->calls);
        }

        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->rows = 0;
        $this->hook = null;
    }
}

function triangle(int $rows): int
{
    return intdiv($rows * ($rows - 1), 2);
}

// Converting the values a scalar function returns costs about 70 us per
// row, and about 2 ms under Memcheck, so the large stream has 200,000 rows
// (98 batches), and the harness's Valgrind stage (USE_ZEND_ALLOC=0) streams
// fewer rows that still span several batches and fails on an earlier batch.
$memcheck = getenv('USE_ZEND_ALLOC') === '0';
$large = $memcheck ? 20000 : 200000;
$medium = $memcheck ? 5000 : 30000;
$failingBatch = $memcheck ? 4 : 40;

$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$scalar = new CountingScalar();
$conn->registerScalarFunction('php_id', ['BIGINT'], 'BIGINT', $scalar);
$select = fn(int $rows) => "SELECT php_id(i) AS v FROM range($rows) t(i)";

// AE4: foreach reads every row, and the function ran once per batch.
$count = 0;
$sum = 0;
foreach ($conn->queryStreaming($select($large)) as $row) {
    $count++;
    $sum += $row['v'];
}
check($count === $large && $sum === triangle($large), "foreach reads every row: $count");
check($scalar->rows === $large, "Every row passed through the function once: {$scalar->rows}");
check($scalar->calls >= intdiv($large + 2047, 2048) && $scalar->calls < $large,
    "The function ran once per batch: {$scalar->calls}");
echo "foreach\n";

// A small streaming buffer pauses the statement between fetches, so every
// later fetch resumes it and its callbacks run during that fetch.
$conn->query("SET streaming_buffer_size = '64KB'");

// The same query through every other fetch path.
$paths = [
    'fetchRow' => function ($result) {
        $values = [];
        while (($row = $result->fetchRow(DuckDB\FetchMode::Num)) !== null) {
            $values[] = $row[0];
        }
        return $values;
    },
    'fetchAll' => fn($result) => array_column($result->fetchAll(DuckDB\FetchMode::Num), 0),
    'fetchColumn' => function ($result) {
        $values = [];
        while (($value = $result->fetchColumn()) !== null) {
            $values[] = $value;
        }
        return $values;
    },
    'fetchArrowChunk' => function ($result) {
        $values = [];
        while (($chunk = $result->fetchArrowChunk()) !== null) {
            $values[] = $chunk->rowCount();
        }
        return $values;
    },
    'executeStreaming' => function ($result) {
        $values = [];
        foreach ($result as $row) {
            $values[] = $row['v'];
        }
        return $values;
    },
];
foreach ($paths as $name => $read) {
    $scalar->reset();
    $result = $name === 'executeStreaming'
        ? $conn->prepare($select($medium))->executeStreaming()
        : $conn->queryStreaming($select($medium));
    $values = $read($result);
    if ($name === 'fetchArrowChunk') {
        check(array_sum($values) === $medium && count($values) > 1, "$name returns every row in batches");
    } else {
        check(count($values) === $medium && array_sum($values) === triangle($medium), "$name returns every row");
    }
    check($scalar->rows === $medium, "$name passes every row through the function once");
    echo "$name\n";
}

// A handler exception on a late batch fails the fetch with the handler's
// exception chained, and the connection stays usable.
$scalar->reset();
$cause = new LogicException("batch $failingBatch failed");
$scalar->hook = function (int $batch) use ($cause, $failingBatch) {
    if ($batch === $failingBatch) {
        throw $cause;
    }
};
$read = 0;
$error = failure(function () use ($conn, $select, $large, &$read) {
    foreach ($conn->queryStreaming($select($large)) as $row) {
        $read++;
    }
});
check($error instanceof DuckDB\Exception, 'The failing fetch throws a DuckDB exception: ' . get_class($error));
$link = $error;
while ($link !== null && $link !== $cause) {
    $link = $link->getPrevious();
}
check($link === $cause, 'The handler exception is chained: ' . $error->getMessage());
check($read < $large, "The stream stopped at the failure: $read");
$scalar->reset();
check($conn->query('SELECT php_id(5::BIGINT)')->fetchColumn() === 5, 'The connection is usable after the failure');
$count = 0;
foreach ($conn->queryStreaming($select($medium)) as $row) {
    $count++;
}
check($count === $medium, 'A new stream on the connection reads every row');
echo "handler exception\n";

// bindValue() on another statement does not start a statement, so a
// half-read pumped stream keeps returning rows.
$scalar->reset();
$other = $conn->prepare('SELECT ?::BIGINT AS v');
$result = $conn->queryStreaming($select($medium));
$values = [];
for ($i = 0; $i < intdiv($medium, 2); $i++) {
    $values[] = $result->fetchRow(DuckDB\FetchMode::Num)[0];
}
$other->bindValue(1, 7);
while (($row = $result->fetchRow(DuckDB\FetchMode::Num)) !== null) {
    $values[] = $row[0];
}
check(count($values) === $medium && array_sum($values) === triangle($medium), 'The stream returns every row');
check($other->execute()->fetchColumn() === 7, 'The bound statement runs afterwards');
echo "bindValue keeps the stream\n";

// A newer statement supersedes a pumped stream: its session closes, its
// next native fetch throws, and none of its callbacks runs afterwards.
$scalar->reset();
$result = $conn->queryStreaming($select($large));
check($result->fetchRow() !== null, 'The stream returns a first row');
check($conn->query('SELECT 1 AS v')->fetchColumn() === 1, 'The newer query runs');
$calls = $scalar->calls;
$error = failure(function () use ($result) {
    while ($result->fetchRow() !== null) {
    }
});
check(str_contains($error->getMessage(), 'invalidated by a newer query'), 'Stale fetch: ' . $error->getMessage());
check($conn->query('SELECT php_id(6::BIGINT)')->fetchColumn() === 6, 'The connection is usable');
usleep(100000);
check($scalar->calls === $calls + 1, "Only the newer query called the function: $calls {$scalar->calls}");
check(failure(fn() => $result->fetchRow()) instanceof DuckDB\Exception, 'The stale stream keeps throwing');
echo "superseded by query\n";

// executeStreaming() of a statement that writes through a PHP COPY format.
$dir = copy_scratch();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$result = $conn->prepare("COPY (SELECT range AS i FROM range(5000) ORDER BY i) TO '$dir/s.lines' (FORMAT lines)")
    ->executeStreaming();
check($result->fetchAll() === [['Count' => 5000]], 'The COPY returns its count row');
check(array_column(copy_lines("$dir/s.lines"), 0) === range(0, 4999), 'Every row is written in order');
check(array_values(array_diff($format->log, ['write'])) === ['bind', 'open', 'close'], 'Each handler runs once');
echo "executeStreaming COPY\n";

// exit() in a handler during a streaming fetch ends the process as it does
// during query(): the shutdown function runs and the connection is usable.
$child = <<<'PHP'
<?php
[$path, $mode, $threads] = [$argv[1], $argv[2], (int) $argv[3]];

final class ExitingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    private int $calls = 0;

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        if (++$this->calls === 3) {
            exit(7);
        }

        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

$conn = (new DuckDB\Database(':memory:', ['threads' => $threads]))->connect();
register_shutdown_function(function () use ($conn) {
    echo "shutdown\n";
    echo 'probe ', $conn->query('SELECT 42 AS v')->fetchColumn(), "\n";
});
$conn->registerScalarFunction('php_exit', ['BIGINT'], 'BIGINT', new ExitingScalar());
$sql = 'SELECT php_exit(i) AS v FROM range(100000) t(i)';
if ($mode === 'query') {
    $conn->query($sql)->fetchAll();
} else {
    foreach ($conn->queryStreaming($sql) as $row) {
    }
}
echo "not reached\n";
PHP;
file_put_contents("$dir/child.php", $child);
$args = duckdb_subprocess_args();

function run_child(string $dir, array $args, string $mode, int $threads): array
{
    $command = array_merge([PHP_BINARY], $args, ["$dir/child.php", $dir, $mode, (string) $threads]);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, getenv());
    check(is_resource($process), 'Child process started');
    stream_set_blocking($pipes[1], false);
    $output = '';
    // Preserve a deadlock bound while allowing Memcheck's slower execution.
    $timeout = getenv('USE_ZEND_ALLOC') === '0' ? 300 : 60;
    $deadline = microtime(true) + $timeout;
    do {
        $output .= stream_get_contents($pipes[1]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            proc_close($process);
            throw new RuntimeException("Child exceeded {$timeout}s: $mode $output");
        }
        usleep(20000);
    } while (true);
    $output .= stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $closed = proc_close($process);
    return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $output];
}

// "helper": the connection's helper thread is the only DuckDB thread;
// "workers": DuckDB worker threads reach the callbacks too.
foreach (['helper' => 1, 'workers' => 4] as $label => $threads) {
    $expected = run_child($dir, $args, 'query', $threads);
    check($expected === [7, "shutdown\nprobe 42\n"], "$label query() exit: " . json_encode($expected));
    $actual = run_child($dir, $args, 'stream', $threads);
    check($actual === $expected, "$label streaming exit: " . json_encode($actual));
    echo "$label exit during a streaming fetch\n";
}
?>
--EXPECT--
foreach
fetchRow
fetchAll
fetchColumn
fetchArrowChunk
executeStreaming
handler exception
bindValue keeps the stream
superseded by query
executeStreaming COPY
helper exit during a streaming fetch
workers exit during a streaming fetch
