--TEST--
COPY TO: an undriven COPY holds its connection, refuses appender flushes and keeps running; relative targets
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();
$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 4');
$conn->query('CREATE TABLE t(i INTEGER)');
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$copy = "COPY (SELECT range AS i FROM range(5000000)) TO '$dir/big.lines' (FORMAT lines)";

/** Start the COPY and drive it until DuckDB workers are writing batches. */
function start_copy(DuckDB\Connection $conn, LinesFormat $format, string $copy): DuckDB\PendingQuery
{
    $format->reset();
    $pending = $conn->queryPending($copy);
    while (!in_array('write', $format->log, true)) {
        check(!$pending->isReady(), 'The COPY is still running');
    }
    return $pending;
}

$million = fn(string $name) => "COPY (SELECT range AS i FROM range(1000000)) TO '$dir/$name.lines' (FORMAT lines)";

/** Await a COPY of a million rows and check that its writer finished normally. */
function finish_copy(DuckDB\PendingQuery $pending, LinesFormat $format): void
{
    check($pending->await()->fetchAll() === [['Count' => 1000000]], 'The COPY wrote every row');
    check(!in_array('abort', $format->log, true), 'abort() did not run');
    check(in_array('close', $format->log, true), 'The COPY closed its writer');
}

function resource_in_use(callable $operation, string $what): void
{
    $error = failure($operation);
    check($error instanceof DuckDB\Exception && $error->getErrorType() === DuckDB\ErrorType::ResourceInUse,
        "$what: " . get_class($error) . ': ' . $error->getMessage());
}

// An Appender refuses to flush while the COPY holds the connection, keeps its
// buffered rows and flushes them once the COPY has finished.
$appender = $conn->appender('t');
for ($i = 0; $i < 10; $i++) {
    $appender->appendRow([$i]);
}
$pending = start_copy($conn, $format, $million('appender'));
resource_in_use(fn() => $appender->flush(), 'flush()');
resource_in_use(fn() => $appender->appendRow([10]), 'appendRow()');
resource_in_use(fn() => $appender->close(), 'close()');
resource_in_use(fn() => $conn->query('SELECT 1'), 'query()');
finish_copy($pending, $format);
unset($pending);
$appender->close();
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 10]], 'The appender flushed its rows');
echo "appender refused while the COPY runs\n";

// Freeing an unclosed Appender while the COPY holds the connection neither
// hangs nor touches the COPY. DuckDB refuses the implicit flush, so the
// buffered rows are discarded.
$appender = $conn->appender('t');
$appender->appendRow([11]);
$pending = start_copy($conn, $format, $million('appender-freed'));
unset($appender);
finish_copy($pending, $format);
unset($pending);
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 10]], 'The refused rows were discarded');
echo "appender destructor\n";

// Binding a value starts no statement, so the undriven COPY keeps running
// and completes when awaited.
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$pending = start_copy($conn, $format, $million('bound'));
$statement->bindValue(1, 5);
resource_in_use(fn() => $statement->execute(), 'execute()');
finish_copy($pending, $format);
check($statement->execute()->fetchAll() === [['v' => 5]], 'The binding was kept');
echo "bindValue leaves the COPY running\n";

// Registration leaves an active COPY on its pinned handler.
$prepared = $conn->prepare("COPY (SELECT 2 AS i) TO '$dir/prepared.lines' (FORMAT lines)");
$pending = start_copy($conn, $format, $copy);
$conn->registerCopyToFunction('other', new LinesFormat());
$replacement = new LinesFormat();
$conn->registerCopyToFunction('LINES', $replacement);
$pending->cancel();
check(in_array('abort', $format->log, true), 'The active COPY aborted with the old handler');
check($replacement->log === [], 'The replacement did not handle the active COPY');
unset($pending);
$error = failure(fn() => $prepared->execute());
check(str_contains($error->getMessage(), 'prepare it again'), $error->getMessage());
$conn->query("COPY (SELECT 3 AS i) TO '$dir/replacement.lines' (FORMAT lines)");
check(in_array('close', $replacement->log, true), 'The replacement handles later COPY statements');
echo "registration while running\n";

// Replacing before the first batch also preserves the handler bound to COPY.
$replacement->reset();
$pending = $conn->queryPending("COPY (SELECT 4 AS i) TO '$dir/before-first-batch.lines' (FORMAT lines)");
check(!in_array('write', $replacement->log, true), 'No batch was written before replacement');
$next = new LinesFormat();
$conn->registerCopyToFunction('lines', $next);
$pending->await();
check(in_array('close', $replacement->log, true), 'The bound handler finished the COPY');
check($next->log === [], 'The next handler did not run for the active COPY');
unset($pending);
echo "registration before first batch\n";

// Relative targets are resolved against the process working directory, as
// DuckDB resolves them. Under ZTS, chdir() only moves PHP's own directory.
$next->reset();
chdir($dir);
$conn->query("COPY (SELECT 1 AS i) TO 'relative.lines' (FORMAT lines)");
$path = $next->paths[0];
check(str_ends_with($path, DIRECTORY_SEPARATOR . 'relative.lines') && $path !== 'relative.lines', $path);
check(PHP_ZTS || $path === getcwd() . DIRECTORY_SEPARATOR . 'relative.lines', $path);
check(copy_lines($path) === [[1]], 'The file was written where the writer was told');
unlink($path);
echo "relative target\n";
?>
--EXPECT--
appender refused while the COPY runs
appender destructor
bindValue leaves the COPY running
registration while running
registration before first batch
relative target
