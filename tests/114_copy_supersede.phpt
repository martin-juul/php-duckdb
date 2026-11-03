--TEST--
COPY TO: other use of the connection supersedes an undriven COPY without hanging; relative targets
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

// Freeing an Appender with buffered rows flushes through the connection.
$appender = $conn->appender('t');
for ($i = 0; $i < 10; $i++) {
    $appender->appendRow([$i]);
}
$pending = start_copy($conn, $format, $copy);
unset($appender);
check(failure(fn() => $pending->await()) instanceof DuckDB\Exception, 'The superseded COPY failed');
check(in_array('abort', $format->log, true), 'abort() ran');
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 10]], 'The appender flushed its rows');
echo "appender destructor\n";

// Binding a value starts no statement, so the undriven COPY keeps running
// and completes when awaited.
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$bound = "COPY (SELECT range AS i FROM range(1000000)) TO '$dir/bound.lines' (FORMAT lines)";
$pending = start_copy($conn, $format, $bound);
$statement->bindValue(1, 5);
check($pending->await()->fetchAll() === [['Count' => 1000000]], 'The COPY wrote every row');
check(!in_array('abort', $format->log, true), 'abort() did not run');
check(in_array('close', $format->log, true), 'The COPY closed its writer');
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
appender destructor
bindValue leaves the COPY running
registration while running
registration before first batch
relative target
