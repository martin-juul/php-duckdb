--TEST--
Result: an open stream refuses newer statements with ResourceInUse and stays complete; drained and failed streams release the connection
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

/** The class, message and error type of the exception $operation throws. */
function thrown(callable $operation): array
{
    $error = failure($operation);

    return [get_class($error), $error->getMessage(), $error->getErrorType()];
}

$conn = (new DuckDB\Database())->connect();

// AE1: a stream with unread rows holds the connection, so DuckDB refuses a
// newer query with RESOURCE_IN_USE. The stream's metadata and every row stay
// readable.
$stream = $conn->queryStreaming('SELECT i::BIGINT AS i, (i * 2)::INTEGER AS doubled FROM range(100000) t(i)');
check($stream->fetchRow() === ['i' => 0, 'doubled' => 0], 'The first row');
$statementType = $stream->statementType();
$rowCount = $stream->rowCount();
$refusal = thrown(fn() => $conn->query('SELECT 1 AS one'));
echo $refusal[2]->name, ': ', $refusal[1], "\n";
check($refusal[0] === DuckDB\Exception::class, $refusal[0]);

check($stream->columns() === [['name' => 'i', 'type' => 'BIGINT'], ['name' => 'doubled', 'type' => 'INTEGER']], 'columns()');
check($stream->columnCount() === 2, 'columnCount()');
check($stream->columnName(1) === 'doubled', 'columnName()');
check($stream->columnType(0) === 'BIGINT', 'columnType()');
check($stream->statementType() === $statementType, 'statementType()');
check($stream->rowsChanged() === 0, 'rowsChanged()');
check($stream->rowCount() === $rowCount, 'rowCount()');
$children = $stream->arrowSchema()->toArray()['children'];
check(array_column($children, 'name') === ['i', 'doubled'], 'arrowSchema()');

$next = 1;
while (($row = $stream->fetchRow()) !== null) {
    check($row === ['i' => $next, 'doubled' => $next * 2], 'Row ' . $next);
    $next++;
}
check($next === 100000, 'Every row was readable: ' . $next);
check($conn->query('SELECT 1 AS one')->fetchAll() === [['one' => 1]], 'The drained stream released the connection');
echo "refused query leaves the open stream complete\n";

// A stream drained to the end no longer holds the connection.
$drained = $conn->queryStreaming('SELECT range AS i FROM range(3)');
check(count($drained->fetchAll()) === 3, 'The stream drained');
check($conn->query('SELECT 2 AS two')->fetchAll() === [['two' => 2]], 'The newer query succeeds');
check($drained->fetchRow() === null, 'A drained stream reports exhaustion');
check($drained->fetchAll() === [], 'A drained stream stays exhausted');
check($drained->columnName(0) === 'i', 'A drained stream keeps its metadata');
echo "drained stream reports exhaustion\n";

// A stream whose fetch fails has completed: it no longer holds the
// connection, and it keeps its error before and after a newer query. One
// thread keeps the error from racing an interrupt.
$single = (new DuckDB\Database(':memory:', ['threads' => 1]))->connect();
$failing = $single->queryStreaming("SELECT (CASE WHEN i = 100000 THEN 'boom' ELSE i::VARCHAR END)::INTEGER AS v FROM range(150000) t(i)");
$original = thrown(function () use ($failing) {
    while ($failing->fetchRow() !== null) {
    }
});
check($original[0] === DuckDB\ConversionException::class && str_contains($original[1], 'boom'), $original[1]);
check($original[2] === DuckDB\ErrorType::Conversion, 'The original error type');
check(thrown(fn() => $failing->fetchRow()) === $original, 'A re-fetch throws the original error');
check($single->query('SELECT 3 AS three')->fetchAll() === [['three' => 3]], 'The newer query succeeds');
check(thrown(fn() => $failing->fetchRow()) === $original, 'After a newer query, the failed stream throws the original error');
check($failing->columnName(0) === 'v', 'A failed stream keeps its metadata');
echo "failed stream keeps its error\n";

// A COPY is refused while a stream is open on its connection. Freeing the
// drained stream inside a COPY handler defers its cleanup until the handler
// returns; the native handle is destroyed only once.
$dir = copy_scratch();
$copying = (new DuckDB\Database())->connect();
$format = new LinesFormat();
$copying->registerCopyToFunction('lines', $format);
$stale = $copying->queryStreaming('SELECT range AS i FROM range(100000)');
check($stale->fetchRow() === ['i' => 0], 'The stream started');
$refusal = thrown(fn() => $copying->query("COPY (SELECT 1 AS i) TO '$dir/refused.lines' (FORMAT lines)"));
check($refusal[2] === DuckDB\ErrorType::ResourceInUse, $refusal[1]);
check($format->log === [] && !file_exists("$dir/refused.lines"), 'The refused COPY ran no handler');
check(count($stale->fetchAll()) === 99999, 'The stream kept its rows');
$format->hooks['write'] = function () use (&$stale) {
    $stale = null;
};
$copying->query("COPY (SELECT 1 AS i) TO '$dir/one.lines' (FORMAT lines)");
check($stale === null && in_array('write', $format->log, true), 'The handler freed the drained stream');
check(copy_lines("$dir/one.lines") === [[1]], 'The COPY wrote its row');
check($copying->query('SELECT 4 AS four')->fetchAll() === [['four' => 4]], 'The connection stays usable');
echo "drained stream freed inside a COPY handler\n";
?>
--EXPECT--
ResourceInUse: Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
refused query leaves the open stream complete
drained stream reports exhaustion
failed stream keeps its error
drained stream freed inside a COPY handler
