--TEST--
Appender: while a stream is open, writes that may flush are refused with ResourceInUse and keep buffered rows
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

/** Open a stream with unread rows on $conn. */
function open_stream(DuckDB\Connection $conn): DuckDB\Result
{
    $stream = $conn->queryStreaming('SELECT range AS i FROM range(100000)');
    check($stream->fetchRow() === ['i' => 0], 'The stream started');

    return $stream;
}

/** Whether $stream still returns every remaining row; this drains it. */
function is_complete(DuckDB\Result $stream): bool
{
    $next = 1;
    while (($row = $stream->fetchRow()) !== null) {
        if ($row !== ['i' => $next]) {
            return false;
        }
        $next++;
    }

    return $next === 100000;
}

/** Whether $operation throws DuckDB's RESOURCE_IN_USE refusal. */
function is_refused(callable $operation): bool
{
    try {
        $operation();
    } catch (DuckDB\Exception $error) {
        return $error->getErrorType() === DuckDB\ErrorType::ResourceInUse
            && $error->getMessage() === 'Resource In Use Error: connection has an open result; drain or destroy it before starting a new query';
    }

    return false;
}

/** An appender on $table with $rows buffered rows. */
function buffered(DuckDB\Connection $conn, string $table, int $rows): DuckDB\Appender
{
    $appender = $conn->appender($table);
    for ($i = 0; $i < $rows; $i++) {
        $appender->appendRow([$i]);
    }

    return $appender;
}

function count_rows(DuckDB\Connection $conn, string $table): int
{
    return $conn->query("SELECT count(*) AS n FROM $table")->fetchAll()[0]['n'];
}

$conn = (new DuckDB\Database())->connect();

// AE6, R8: flush() and close() are refused while the stream is open, before
// DuckDB could discard the buffer. The rows reach the table once the stream
// has drained.
$endings = [
    'flush' => fn(DuckDB\Appender $appender) => $appender->flush(),
    'close' => fn(DuckDB\Appender $appender) => $appender->close(),
];
foreach ($endings as $name => $ending) {
    $conn->query("CREATE TABLE $name(i INTEGER)");
    $appender = buffered($conn, $name, 10);
    $stream = open_stream($conn);
    check(is_refused(fn() => $ending($appender)), "$name was not refused");
    check(is_complete($stream), "$name cut the stream short");
    check(count_rows($conn, $name) === 0, "$name wrote rows while refused");
    $ending($appender);
    check(count_rows($conn, $name) === 10, "$name lost the buffered rows");
    echo "$name is refused and keeps 10 buffered rows\n";
}

// Destroying an unclosed appender flushes on a best-effort basis: after the
// stream drains, the rows reach the table; while the stream is open, DuckDB
// refuses the flush and the buffered rows are discarded.
$conn->query('CREATE TABLE destructor(i INTEGER)');
$appender = buffered($conn, 'destructor', 10);
$appender = null;
check(count_rows($conn, 'destructor') === 10, 'The destructor flushed with no result open');
$appender = buffered($conn, 'destructor', 10);
$stream = open_stream($conn);
$appender = null;
check(is_complete($stream), 'The destructor cut the stream short');
check(count_rows($conn, 'destructor') === 10, 'The refused destructor flush wrote rows');
echo "destructor flushes only without an open result\n";

// Row operations that may flush are refused and leave the appender usable;
// values appended inside a row are buffered and kept.
$conn->query('CREATE TABLE rows(i INTEGER)');
$appender = $conn->appender('rows');
$chunk = DuckDB\DataChunk::fromVectors(['i' => $conn->createVector('INTEGER', 2)], 2);
$arrow = $chunk->toArrow($conn);
$stream = open_stream($conn);
check(is_refused(fn() => $appender->appendRow([1])), 'appendRow() was not refused');
check(is_refused(fn() => $appender->appendChunk($chunk)), 'appendChunk() was not refused');
check(is_refused(fn() => $appender->appendArrow($arrow)), 'appendArrow() was not refused');
$appender->beginRow();
$appender->append(2);
check(is_refused(fn() => $appender->endRow()), 'endRow() was not refused');
check(is_complete($stream), 'The refused row operations cut the stream short');
$appender->endRow();
$appender->appendRow([3]);
$appender->close();
check($conn->query('SELECT i FROM rows ORDER BY i')->fetchAll() === [['i' => 2], ['i' => 3]],
    'Only the accepted rows reached the table');
echo "row operations are refused and the appender stays usable\n";

// An appender freed inside a COPY handler on its connection closes once the
// handler returns, and keeps its rows.
$dir = copy_scratch();
$conn->query('CREATE TABLE deferred(i INTEGER)');
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);
$appender = buffered($conn, 'deferred', 10);
$format->hooks['write'] = function () use (&$appender) {
    $appender = null;
};
$conn->query("COPY (SELECT 1 AS i) TO '$dir/one.lines' (FORMAT lines)");
check($appender === null && in_array('write', $format->log, true), 'The handler freed the appender');
check(count_rows($conn, 'deferred') === 10, 'The deferred cleanup kept the buffered rows');
echo "deferred cleanup keeps 10 buffered rows\n";

// Appending past DuckDB's internal flush threshold flushes every row once
// no result holds the connection.
$conn->query('CREATE TABLE many(i INTEGER)');
$appender = $conn->appender('many');
$stream = open_stream($conn);
check(is_refused(fn() => $appender->appendRow([0])), 'appendRow() was not refused');
unset($stream);
for ($i = 0; $i < 250000; $i++) {
    $appender->appendRow([$i]);
}
$appender->close();
check($conn->query('SELECT count(*) AS n, sum(i)::BIGINT AS s FROM many')->fetchAll() === [['n' => 250000, 's' => 31249875000]],
    'Every appended row is in the table');
echo "appendRow keeps 250000 rows\n";

// DuckDB collects a streaming INSERT ... RETURNING in full, so it does not
// hold the connection, and the appender writes beside it.
$conn->query('CREATE TABLE returned(i INTEGER)');
$conn->query('CREATE TABLE beside(i INTEGER)');
$appender = $conn->appender('beside');
$returning = $conn->queryStreaming('INSERT INTO returned SELECT range FROM range(5000) RETURNING i');
check($returning->fetchRow() === ['i' => 0], 'The RETURNING stream started');
$appender->appendRow([1]);
$appender->flush();
check(count_rows($conn, 'beside') === 1, 'The row beside RETURNING reached the table');
check(count($returning->fetchAll()) === 4999, 'The RETURNING stream returned every row');
$stream = open_stream($conn);
check(is_refused(fn() => $appender->appendRow([2])), 'appendRow() beside a held stream was not refused');
unset($stream);
$appender->close();
check(count_rows($conn, 'beside') === 1 && count_rows($conn, 'returned') === 5000, 'Only the accepted rows were written');
echo "an INSERT ... RETURNING stream does not hold the connection\n";
?>
--EXPECT--
flush is refused and keeps 10 buffered rows
close is refused and keeps 10 buffered rows
destructor flushes only without an open result
row operations are refused and the appender stays usable
deferred cleanup keeps 10 buffered rows
appendRow keeps 250000 rows
an INSERT ... RETURNING stream does not hold the connection
