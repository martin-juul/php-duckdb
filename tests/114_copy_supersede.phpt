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

// Any other entry to the connection supersedes the undriven COPY, with a
// message that says so.
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$pending = start_copy($conn, $format, $copy);
$statement->bindValue(1, 5);
$error = failure(fn() => $pending->await());
check(str_contains($error->getMessage(), 'COPY statement superseded by another operation on its connection'),
    $error->getMessage());
check(in_array('abort', $format->log, true), 'abort() ran');
check($statement->execute()->fetchAll() === [['v' => 5]], 'The connection stays usable');
echo "superseded by bindValue\n";

// Formats cannot be registered while a COPY statement is open.
$pending = start_copy($conn, $format, $copy);
$error = failure(fn() => $conn->registerCopyToFunction('other', new LinesFormat()));
check(str_contains($error->getMessage(), 'cannot be registered while a COPY statement runs'), $error->getMessage());
$pending->cancel();
unset($pending);
echo "registration refused while running\n";

// Relative targets are resolved against the process working directory.
$format->reset();
chdir($dir);
$conn->query("COPY (SELECT 1 AS i) TO 'relative.lines' (FORMAT lines)");
check($format->paths === [getcwd() . DIRECTORY_SEPARATOR . 'relative.lines'], json_encode($format->paths));
check(copy_lines("$dir/relative.lines") === [[1]], 'The file was written in the working directory');
echo "relative target\n";
?>
--EXPECT--
appender destructor
superseded by bindValue
registration refused while running
relative target
