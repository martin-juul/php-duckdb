--TEST--
Replacing deferred typed bindings releases PHP destructors outside the connection mutex
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
require_once __DIR__ . '/subprocess.inc';
if (!function_exists('proc_open')) { die('skip proc_open unavailable'); }
if (duckdb_subprocess_args() === null) { die('skip cannot locate extension for subprocess'); }
?>
--FILE--
<?php
require_once __DIR__ . '/subprocess.inc';
$child = <<<'PHP'
class QueryingValue extends DuckDB\Value {
    public static int $destroyed = 0;
    public function __construct(private DuckDB\Connection $conn) {
        parent::__construct('INTEGER', 1);
    }
    public function __destruct() {
        if ($this->conn->query('SELECT 1 AS n')->fetchRow()['n'] !== 1) {
            throw new RuntimeException('Destructor query failed');
        }
        self::$destroyed++;
    }
}
$conn = (new DuckDB\Database())->connect();
foreach (['clear', 'nested-clear', 'typed-bind', 'ordinary-bind', 'blob-bind',
          'execute-ordinary', 'execute-typed', 'async-ordinary', 'async-typed'] as $mode) {
    $stmt = $conn->prepare('SELECT ? AS v');
    $initial = new QueryingValue($conn);
    $stmt->bindValue(1, $mode === 'nested-clear' ? [$initial] : $initial);
    unset($initial);
    $before = QueryingValue::$destroyed;
    switch ($mode) {
        case 'clear':
        case 'nested-clear': $stmt->clearBindings(); break;
        case 'typed-bind': $stmt->bindValue(1, new DuckDB\Integer(2)); break;
        case 'ordinary-bind': $stmt->bindValue(1, 2); break;
        case 'blob-bind': $stmt->bindBlob(1, 'two'); break;
        case 'execute-ordinary': $result = $stmt->execute([2]); break;
        case 'execute-typed': $result = $stmt->execute([new DuckDB\Integer(2)]); break;
        case 'async-ordinary': $result = $stmt->executeAsync([2])->await(); break;
        case 'async-typed': $result = $stmt->executeAsync([new DuckDB\Integer(2)])->await(); break;
    }
    if (QueryingValue::$destroyed !== $before + 1) {
        throw new RuntimeException("Destructor not released: $mode");
    }
    if (isset($result)) {
        if ($result->fetchRow()['v'] !== 2) { throw new RuntimeException("Wrong value: $mode"); }
        unset($result);
    }
    unset($stmt);
    echo $mode, ": ok\n";
}
PHP;
$proc = proc_open(array_merge([PHP_BINARY], duckdb_subprocess_args(), ['-r', $child]),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) { throw new RuntimeException('Cannot start destructor regression subprocess'); }
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$output = $error = '';
$deadline = hrtime(true) + 15_000_000_000;
$exit = -1;
while (true) {
    $output .= stream_get_contents($pipes[1]);
    $error .= stream_get_contents($pipes[2]);
    $status = proc_get_status($proc);
    if (!$status['running']) { $exit = $status['exitcode']; break; }
    if (hrtime(true) > $deadline) {
        proc_terminate($proc, 9);
        $error .= "destructor regression subprocess timed out\n";
        break;
    }
    usleep(10000);
}
$output .= stream_get_contents($pipes[1]);
$error .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$closed = proc_close($proc);
if ($exit === -1) { $exit = $closed; }
echo $output;
if ($exit !== 0 || $error !== '') { echo "subprocess failed: $exit\n$error"; }
?>
--EXPECT--
clear: ok
nested-clear: ok
typed-bind: ok
ordinary-bind: ok
blob-bind: ok
execute-ordinary: ok
execute-typed: ok
async-ordinary: ok
async-typed: ok
