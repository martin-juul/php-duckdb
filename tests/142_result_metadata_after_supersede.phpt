--TEST--
Result: a superseded stream keeps its buffered rows, metadata and errors; drained streams are left alone
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

// AE1: a newer query supersedes a stream with unread rows. The rows it
// already buffered and its metadata stay readable; the next native fetch
// throws the stale message.
$stream = $conn->queryStreaming('SELECT i::BIGINT AS i, (i * 2)::INTEGER AS doubled FROM range(100000) t(i)');
check($stream->fetchRow() === ['i' => 0, 'doubled' => 0], 'The first row');
$statementType = $stream->statementType();
$rowCount = $stream->rowCount();
check($conn->query('SELECT 1 AS one')->fetchAll() === [['one' => 1]], 'The newer query succeeds');

$next = 1;
$error = failure(function () use ($stream, &$next) {
    while (($row = $stream->fetchRow()) !== null) {
        check($row === ['i' => $next, 'doubled' => $next * 2], 'Buffered row ' . $next);
        $next++;
    }
});
check($next > 1 && $next < 100000, 'Buffered rows were readable: ' . $next);
check(get_class($error) === DuckDB\Exception::class, get_class($error));
check(str_starts_with($error->getMessage(), 'This streaming result was invalidated by a newer query'), $error->getMessage());
// The extension raises this itself, with code 0 as on 1.5, never
// DuckDB's RESOURCE_IN_USE.
check($error->getCode() === 0 && $error->getErrorType() === DuckDB\ErrorType::Invalid, 'A supersede is not a DuckDB error');
check(thrown(fn() => $stream->fetchRow()) === [DuckDB\Exception::class, $error->getMessage(), DuckDB\ErrorType::Invalid], 'The stream stays stale');

check($stream->columns() === [['name' => 'i', 'type' => 'BIGINT'], ['name' => 'doubled', 'type' => 'INTEGER']], 'columns()');
check($stream->columnCount() === 2, 'columnCount()');
check($stream->columnName(1) === 'doubled', 'columnName()');
check($stream->columnType(0) === 'BIGINT', 'columnType()');
check($stream->statementType() === $statementType, 'statementType()');
check($stream->rowsChanged() === 0, 'rowsChanged()');
check($stream->rowCount() === $rowCount, 'rowCount()');
$children = $stream->arrowSchema()->toArray()['children'];
check(array_column($children, 'name') === ['i', 'doubled'], 'arrowSchema()');
echo "superseded stream keeps buffered rows and metadata\n";

// A stream drained to the end is not superseded: it reports exhaustion.
$drained = $conn->queryStreaming('SELECT range AS i FROM range(3)');
check(count($drained->fetchAll()) === 3, 'The stream drained');
check($conn->query('SELECT 2 AS two')->fetchAll() === [['two' => 2]], 'The newer query succeeds');
check($drained->fetchRow() === null, 'A drained stream reports exhaustion');
check($drained->fetchAll() === [], 'A drained stream stays exhausted');
check($drained->columnName(0) === 'i', 'A drained stream keeps its metadata');
echo "drained stream reports exhaustion\n";

// A stream whose fetch fails keeps its error, before and after a newer
// query supersedes it. One thread keeps the error from racing an interrupt.
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
check(thrown(fn() => $failing->fetchRow()) === $original, 'A superseded failed stream throws the original error');
check($failing->columnName(0) === 'v', 'A failed stream keeps its metadata');
echo "failed stream keeps its error\n";

// Freeing a stale stream inside a COPY handler defers its cleanup until the
// handler returns; the native handle is destroyed only once.
$dir = copy_scratch();
$copying = (new DuckDB\Database())->connect();
$format = new LinesFormat();
$copying->registerCopyToFunction('lines', $format);
$stale = $copying->queryStreaming('SELECT range AS i FROM range(100000)');
check($stale->fetchRow() === ['i' => 0], 'The stream started');
$format->hooks['write'] = function () use (&$stale) {
    $stale = null;
};
$copying->query("COPY (SELECT 1 AS i) TO '$dir/one.lines' (FORMAT lines)");
check($stale === null && in_array('write', $format->log, true), 'The handler freed the stale stream');
check(copy_lines("$dir/one.lines") === [[1]], 'The COPY wrote its row');
check($copying->query('SELECT 4 AS four')->fetchAll() === [['four' => 4]], 'The connection stays usable');
echo "stale stream freed inside a COPY handler\n";
?>
--EXPECT--
superseded stream keeps buffered rows and metadata
drained stream reports exhaustion
failed stream keeps its error
stale stream freed inside a COPY handler
