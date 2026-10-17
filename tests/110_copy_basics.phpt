--TEST--
COPY TO: PHP formats receive every batch in order through every pumped execution path
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

$dir = copy_scratch();
$conn = (new DuckDB\Database())->connect();
$format = new LinesFormat();
$conn->registerCopyToFunction('lines', $format);

// AE1: 5000 rows in order, one open and one close.
$conn->query('SET threads = 4');
$result = $conn->query("COPY (SELECT range AS i FROM range(5000) ORDER BY i) TO '$dir/a.lines' (FORMAT lines)");
check($result->fetchAll() === [['Count' => 5000]], 'COPY reports its row count');
check(array_column(copy_lines("$dir/a.lines"), 0) === range(0, 4999), 'Every row arrives in order');
$counts = array_count_values($format->log);
check($counts['bind'] === 1 && $counts['open'] === 1 && $counts['close'] === 1, 'One bind, open and close');
check($counts['write'] >= 3, 'Batches arrive one vector at a time');
check(!in_array('abort', $format->log, true), 'A successful COPY is not aborted');
echo "5000 rows in order\n";

// Every pumped execution path.
$sql = fn(string $name) => "COPY (SELECT range AS i FROM range(3)) TO '$dir/$name.lines' (FORMAT lines)";
$paths = [
    'execute' => fn() => $conn->execute($sql('execute')),
    'queryStreaming' => function () use ($conn, $sql) {
        foreach ($conn->queryStreaming($sql('queryStreaming')) as $row) {
        }
    },
    'executeStreaming' => function () use ($conn, $sql) {
        foreach ($conn->prepare($sql('executeStreaming'))->executeStreaming() as $row) {
        }
    },
    'queryPending' => fn() => $conn->queryPending($sql('queryPending'))->await(),
    'suspend' => function () use ($conn, $sql) {
        $fiber = new Fiber(fn() => $conn->queryPending($sql('suspend'))->suspend());
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }
    },
];
foreach ($paths as $name => $run) {
    $format->reset();
    $run();
    check(array_column(copy_lines("$dir/$name.lines"), 0) === [0, 1, 2], "$name writes every row");
    check(array_values(array_diff($format->log, ['write'])) === ['bind', 'open', 'close'], "$name calls each handler once");
    echo "$name\n";
}

// A prepared statement executed three times opens three writers.
$format->reset();
$statement = $conn->prepare("COPY (SELECT ?::INTEGER AS v) TO '$dir/prepared.lines' (FORMAT lines)");
foreach ([7, 8, 9] as $value) {
    $statement->execute([$value]);
    check(copy_lines("$dir/prepared.lines") === [[$value]], 'Each execution writes its own file');
}
$counts = array_count_values($format->log);
check($counts['open'] === 3 && $counts['close'] === 3, 'Three executions, three writers');
echo "prepared x3\n";

// Format chosen by file extension, and case-insensitive names.
$tsv = new LinesFormat();
$conn->registerCopyToFunction('TsV2', $tsv);
$conn->query("COPY (SELECT 1 AS one) TO '$dir/by-extension.tsv2'");
$conn->query("COPY (SELECT 2 AS two) TO '$dir/upper.out' (FORMAT TSV2)");
check(copy_lines("$dir/by-extension.tsv2") === [[1]] && copy_lines("$dir/upper.out") === [[2]], 'Extension and case');
check(count(array_keys($tsv->log, 'close')) === 2, 'Both statements used the registered format');
echo "extension and case\n";

// bind() receives SQL types and decoded options.
$format->reset();
$conn->query("CREATE TYPE mood AS ENUM ('ok', 'sad')");
$conn->query(<<<SQL
    COPY (SELECT 1::INTEGER AS a, 1.5::DECIMAL(18,3) AS b, ['x']::VARCHAR[] AS c,
                 {'k': 1, 'v': 'w'} AS d, 'ok'::mood AS e, 'POINT(1 2)'::GEOMETRY AS f)
    TO '$dir/types.lines' (FORMAT lines)
    SQL);
check($format->binds[0][0] === [
    'INTEGER', 'DECIMAL(18,3)', 'VARCHAR[]', 'STRUCT("k" INTEGER, "v" VARCHAR)', "ENUM('ok', 'sad')", 'GEOMETRY',
], 'Column types: ' . json_encode($format->binds[0][0]));
check($format->binds[0][1] === [], 'No options decode to an empty array');
$format->reset();
$conn->query("COPY (SELECT 1) TO '$dir/options.lines' (FORMAT lines, quality 7, Header, cols ['a', 'b'], mixed (1, 'x'))");
check($format->binds[0][1] === ['COLS' => ['a', 'b'], 'HEADER' => null, 'MIXED' => [1, 'x'], 'QUALITY' => 7],
    'Options: ' . json_encode($format->binds[0][1]));
echo "types and options\n";

// Zero rows: the writer still opens and closes, unless no file is wanted.
$format->reset();
$conn->query("COPY (SELECT 1 AS i WHERE false) TO '$dir/empty.lines' (FORMAT lines)");
check($format->log === ['bind', 'open', 'close'] && copy_lines("$dir/empty.lines") === [], 'Empty COPY');
$format->reset();
@unlink("$dir/skipped.lines");
$conn->query("COPY (SELECT 1 AS i WHERE false) TO '$dir/skipped.lines' (FORMAT lines, WRITE_EMPTY_FILE false)");
check($format->log === ['bind'] && !file_exists("$dir/skipped.lines"), 'No file: ' . json_encode($format->log));
echo "zero rows\n";

// An existing target is written through DuckDB's temporary file.
$format->reset();
file_put_contents("$dir/existing.lines", "old\n");
$conn->query("COPY (SELECT 5 AS i) TO '$dir/existing.lines' (FORMAT lines)");
check(basename($format->paths[0]) === 'tmp_existing.lines', 'Temporary path: ' . $format->paths[0]);
check(copy_lines("$dir/existing.lines") === [[5]] && !file_exists($format->paths[0]), 'Renamed into place');
echo "temporary file\n";

// A batch is only valid during write(); copies made there stay valid.
$format->reset();
$kept = [];
$format->hooks['write'] = function (DuckDB\DataChunk $batch) use (&$kept) {
    $kept = [$batch, $batch->select([1, 0]), $batch->vector(0)];
};
$conn->query("COPY (SELECT range AS i, 'v' || range AS s FROM range(2)) TO '$dir/kept.lines' (FORMAT lines)");
$format->hooks = [];
[$batch, $selected, $vector] = $kept;
$expired = failure(fn() => $batch->rowCount());
check($expired instanceof Error && str_contains($expired->getMessage(), 'only valid during CopyToWriter::write()'),
    'Expired batch: ' . $expired->getMessage());
check($selected->toRows() === [['col0' => 1, 'col1' => 'v1'], ['col0' => 0, 'col1' => 'v0']], 'select() copy');
check($vector->toArray() === [0, 1], 'vector() copy');
echo "batch lifetime\n";
?>
--EXPECT--
5000 rows in order
execute
queryStreaming
executeStreaming
queryPending
suspend
prepared x3
extension and case
types and options
zero rows
temporary file
batch lifetime
