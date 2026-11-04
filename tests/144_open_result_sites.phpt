--TEST--
Connection: every synchronous statement start is refused with ResourceInUse while a stream is open; binding, metadata, Arrow, registration and appender creation leave it readable
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

$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t(i INTEGER)');
$conn->query("CREATE TYPE mood AS ENUM('calm', 'busy')");
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$appender = $conn->appender('t');
$vector = $conn->createVector('INTEGER', 1);
$chunk = DuckDB\DataChunk::fromVectors(['v' => $conn->createVector('INTEGER', 1)], 1);
$arrow = $chunk->toArrow($conn);

// R6: each operation starts a statement, so DuckDB refuses it while the
// stream is open, and the stream keeps all of its rows. Once the stream has
// drained, the same operation succeeds.
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
    check(is_refused($operation), $name . ' was not refused');
    check(is_complete($stream), $name . ' cut the stream short');
    $operation();
    echo $name, " is refused while the stream is open\n";
}
$appender->close();
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 1]], 'Only the accepted appendRow wrote a row');

$stream = open_stream($conn);
check(is_refused(fn() => $conn->beginTransaction()), 'beginTransaction() was not refused');
check(is_complete($stream), 'beginTransaction() cut the stream short');
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (11)');
$stream = open_stream($conn);
check(is_refused(fn() => $conn->rollBack()), 'rollBack() was not refused');
check(is_refused(fn() => $conn->commit()), 'commit() was not refused');
check(is_complete($stream), 'rollBack() and commit() cut the stream short');
check($conn->query('SELECT count(*) AS n FROM t WHERE i = 11')->fetchAll() === [['n' => 1]], 'The transaction is still open');
$conn->rollBack();
check($conn->query('SELECT count(*) AS n FROM t WHERE i = 11')->fetchAll() === [['n' => 0]], 'The rollback ran');
echo "beginTransaction, commit and rollBack are refused while the stream is open\n";

// An undriven pending query holds the connection the same way.
$pending = $conn->queryPending('SELECT 42 AS v');
check(is_refused(fn() => $conn->query('SELECT 1')), 'A pending query did not hold the connection');
check($pending->await()->fetchAll() === [['v' => 42]], 'The pending query completes');
check($conn->query('SELECT 1 AS one')->fetchAll() === [['one' => 1]], 'The completed pending query released the connection');
echo "an undriven pending query refuses statements\n";

// AE2, R7: these operations start no statement, so they succeed and the
// stream keeps returning rows.
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
    'appender creation' => fn() => $conn->appender('t'),
];
foreach ($plain as $name => $operation) {
    $stream = open_stream($conn);
    $operation();
    check($stream->fetchRow() === ['i' => 1], $name . ' disturbed the stream');
    echo $name, " leaves the stream readable\n";

    // Releasing the undrained stream frees the connection.
    unset($stream);
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
query is refused while the stream is open
queryStreaming is refused while the stream is open
prepare is refused while the stream is open
Connection::execute with a typed parameter is refused while the stream is open
Statement::execute with a typed parameter is refused while the stream is open
Statement::executeStreaming is refused while the stream is open
queryPending is refused while the stream is open
typed appendRow is refused while the stream is open
Value::toString is refused while the stream is open
createVector is refused while the stream is open
Vector::set is refused while the stream is open
Vector::setValues is refused while the stream is open
getTableFunctions is refused while the stream is open
beginTransaction, commit and rollBack are refused while the stream is open
an undriven pending query refuses statements
bindValue leaves the stream readable
typed bindValue leaves the stream readable
clearBindings leaves the stream readable
getTableNames leaves the stream readable
dataChunkFromArrow leaves the stream readable
DataChunk::toArrow leaves the stream readable
registerCopyToFunction leaves the stream readable
registerScalarFunction leaves the stream readable
appender creation leaves the stream readable
registered function runs
registration resolves the USE catalog
