--TEST--
Appender: buffered rows survive an open stream through flush, close, destruction, deferred cleanup and internal flushes
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

/** Whether reading $stream to the end throws the stale-stream message. */
function is_stale(DuckDB\Result $stream): bool
{
    try {
        while ($stream->fetchRow() !== null) {
        }
    } catch (DuckDB\Exception $error) {
        return str_starts_with($error->getMessage(), 'This streaming result was invalidated by a newer query');
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

// AE6, R8: the rows reach the table whichever way the appender finishes.
$endings = [
    'destructor' => function (DuckDB\Appender &$appender): void {
        $appender = null;
    },
    'flush' => fn(DuckDB\Appender $appender) => $appender->flush(),
    'close' => fn(DuckDB\Appender $appender) => $appender->close(),
];
foreach ($endings as $name => $ending) {
    $conn->query("CREATE TABLE $name(i INTEGER)");
    $appender = buffered($conn, $name, 10);
    $stream = open_stream($conn);
    $ending($appender);
    check(count_rows($conn, $name) === 10, "$name kept the buffered rows");
    check(is_stale($stream), "$name left the stream readable");
    echo "$name keeps 10 buffered rows\n";
}

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

// Appending past DuckDB's internal flush threshold with a stream open
// flushes every row.
$conn->query('CREATE TABLE many(i INTEGER)');
$appender = $conn->appender('many');
$stream = open_stream($conn);
for ($i = 0; $i < 250000; $i++) {
    $appender->appendRow([$i]);
}
$appender->close();
check($conn->query('SELECT count(*) AS n, sum(i)::BIGINT AS s FROM many')->fetchAll() === [['n' => 250000, 's' => 31249875000]],
    'Every appended row is in the table');
check(is_stale($stream), 'appendRow() left the stream readable');
echo "appendRow keeps 250000 rows\n";
?>
--EXPECT--
destructor keeps 10 buffered rows
flush keeps 10 buffered rows
close keeps 10 buffered rows
deferred cleanup keeps 10 buffered rows
appendRow keeps 250000 rows
