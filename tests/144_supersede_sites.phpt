--TEST--
Connection: every synchronous statement start supersedes an open stream; binding, metadata, Arrow and registration leave it readable
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

final class Labelled implements DuckDB\ScalarFunction
{
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return new class implements DuckDB\ScalarBinding {
            public function init(): DuckDB\ScalarExecutor
            {
                return new class implements DuckDB\ScalarExecutor {
                    public function execute(DuckDB\DataChunk $arguments): array
                    {
                        $out = [];
                        foreach ($arguments->toRows(DuckDB\FetchMode::Num) as [$value]) {
                            $out[] = 'label ' . $value;
                        }

                        return $out;
                    }
                };
            }
        };
    }
}

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

$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t(i INTEGER)');
$conn->query("CREATE TYPE mood AS ENUM('calm', 'busy')");
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$appender = $conn->appender('t');
$vector = $conn->createVector('INTEGER', 1);
$chunk = DuckDB\DataChunk::fromVectors(['v' => $conn->createVector('INTEGER', 1)], 1);
$arrow = $chunk->toArrow($conn);

// R6: each operation starts a statement, so it succeeds and the open stream
// becomes stale.
$starts = [
    'query' => fn() => check($conn->query('SELECT 1 AS one')->fetchAll() === [['one' => 1]], 'query()'),
    'queryStreaming' => fn() => check($conn->queryStreaming('SELECT 2 AS two')->fetchAll() === [['two' => 2]],
        'queryStreaming()'),
    'prepare' => fn() => $conn->prepare('SELECT 3'),
    'Connection::execute with a typed parameter' => fn() => check(
        $conn->execute('SELECT ? AS v', [new DuckDB\Value('INTEGER', '4')])->fetchAll() === [['v' => 4]],
        'Connection::execute()'),
    'Statement::execute with a typed parameter' => fn() => check(
        $statement->execute([new DuckDB\Value('INTEGER', '5')])->fetchAll() === [['v' => 5]], 'Statement::execute()'),
    'Statement::executeStreaming' => fn() => check($statement->executeStreaming([6])->fetchAll() === [['v' => 6]],
        'Statement::executeStreaming()'),
    'queryPending' => fn() => check($conn->queryPending('SELECT 7 AS v')->await()->fetchAll() === [['v' => 7]],
        'queryPending()'),
    'appender creation' => fn() => $conn->appender('t')->close(),
    'typed appendRow' => fn() => $appender->appendRow([new DuckDB\Value('INTEGER', '8')]),
    'Value::toString' => fn() => check((new DuckDB\Value('mood', 'busy'))->toString($conn) === 'busy',
        'Value::toString()'),
    'createVector' => fn() => $conn->createVector('mood', 1),
    'Vector::set' => fn() => $vector->set($conn, 0, '9'),
    'Vector::setValues' => fn() => $vector->setValues($conn, ['10']),
    'getTableFunctions' => fn() => check($conn->getTableFunctions('range') !== [], 'getTableFunctions()'),
];
foreach ($starts as $name => $operation) {
    $stream = open_stream($conn);
    $operation();
    check(is_stale($stream), $name . ' left the stream readable');
    echo $name, " supersedes\n";
}

$stream = open_stream($conn);
$conn->beginTransaction();
check(is_stale($stream), 'beginTransaction() left the stream readable');
$conn->query('INSERT INTO t VALUES (11)');
$stream = open_stream($conn);
$conn->rollBack();
check(is_stale($stream), 'rollBack() left the stream readable');
check($conn->query('SELECT count(*) AS n FROM t WHERE i = 11')->fetchAll() === [['n' => 0]], 'The rollback ran');
echo "beginTransaction and rollBack supersede\n";

// AE2, R7: these operations start no statement, so the stream keeps
// returning rows.
$other = $conn->prepare('SELECT ? AS v');
$plain = [
    'bindValue' => fn() => $other->bindValue(1, 12),
    'typed bindValue' => fn() => $other->bindValue(1, new DuckDB\Value('mood', 'calm')),
    'clearBindings' => fn() => $other->clearBindings(),
    'getTableNames' => fn() => check($conn->getTableNames('SELECT * FROM t') === ['t'], 'getTableNames()'),
    'dataChunkFromArrow' => fn() => check($conn->dataChunkFromArrow($arrow)->rowCount() === 1, 'dataChunkFromArrow()'),
    'DataChunk::toArrow' => fn() => $chunk->toArrow($conn),
    'registerCopyToFunction' => fn() => $conn->registerCopyToFunction('lines', new LinesFormat()),
    'registerScalarFunction' => fn() => $conn->registerScalarFunction('php_label', ['mood'], 'VARCHAR', new Labelled()),
];
foreach ($plain as $name => $operation) {
    $stream = open_stream($conn);
    $operation();
    check($stream->fetchRow() === ['i' => 1], $name . ' superseded the stream');
    echo $name, " leaves the stream readable\n";
}
check($conn->query("SELECT php_label('busy'::mood) AS v")->fetchAll() === [['v' => 'label busy']],
    'The function registered with a stream open runs');
echo "registered function runs\n";

// With no result open, registration resolves types on the connection itself,
// so it sees the catalog selected with USE, as on 1.5.0.
$conn->query("ATTACH ':memory:' AS other");
$conn->query('USE other');
$conn->query("CREATE TYPE shade AS ENUM('light', 'dark')");
$conn->registerScalarFunction('php_shade', ['shade'], 'VARCHAR', new Labelled());
check($conn->query("SELECT php_shade('dark'::shade) AS v")->fetchAll() === [['v' => 'label dark']],
    'The function takes the USE catalog type');
echo "registration resolves the USE catalog\n";
?>
--EXPECT--
query supersedes
queryStreaming supersedes
prepare supersedes
Connection::execute with a typed parameter supersedes
Statement::execute with a typed parameter supersedes
Statement::executeStreaming supersedes
queryPending supersedes
appender creation supersedes
typed appendRow supersedes
Value::toString supersedes
createVector supersedes
Vector::set supersedes
Vector::setValues supersedes
getTableFunctions supersedes
beginTransaction and rollBack supersede
bindValue leaves the stream readable
typed bindValue leaves the stream readable
clearBindings leaves the stream readable
getTableNames leaves the stream readable
dataChunkFromArrow leaves the stream readable
DataChunk::toArrow leaves the stream readable
registerCopyToFunction leaves the stream readable
registerScalarFunction leaves the stream readable
registered function runs
registration resolves the USE catalog
