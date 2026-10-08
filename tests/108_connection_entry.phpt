--TEST--
Connection entry: closed connections and connection-bound destruction orders
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, string $class): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        return $error->getMessage();
    }

    throw new RuntimeException('Expected rejection');
}

$db = new DuckDB\Database();
$conn = $db->connect();
$conn->query('CREATE TABLE t(i INTEGER)');
$statement = $conn->prepare('SELECT ?::INTEGER AS v');
$appender = $conn->appender('t');
$vector = $conn->createVector('INTEGER', 1);
$chunk = DuckDB\DataChunk::fromVectors(['v' => $vector], 1);
$conn->close();

$messages = [];
$messages[] = rejects(fn() => $conn->query('SELECT 1'), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $conn->prepare('SELECT 1'), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $conn->queryPending('SELECT 1'), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $conn->getTableNames('SELECT 1'), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $conn->beginTransaction(), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $statement->execute([1]), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $appender->appendRow([1]), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $vector->set($conn, 0, 1), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => $chunk->toArrow($conn), DuckDB\ConnectionException::class);
$messages[] = rejects(fn() => (new DuckDB\Integer(1))->toString($conn), DuckDB\ConnectionException::class);
check(count(array_unique($messages)) === 1, 'Closed connections report one message');
echo $messages[0], "\n";
unset($statement, $appender, $vector, $chunk, $conn, $db);

function build(): array
{
    $conn = (new DuckDB\Database())->connect();
    $conn->query('CREATE TABLE t(i INTEGER)');
    $stream = $conn->queryStreaming('SELECT * FROM range(5000) t(i)');
    $stream->fetchRow();
    $pending = $conn->queryPending('SELECT count(*) FROM range(100000)');
    $appender = $conn->appender('t');
    $appender->appendRow([1]);
    return [$conn, $stream, $pending, $appender];
}

$orders = [
    [0, 1, 2, 3],
    [3, 2, 1, 0],
    [1, 0, 3, 2],
    [2, 3, 0, 1],
];
foreach ($orders as $order) {
    $objects = build();
    foreach ($order as $index) {
        unset($objects[$index]);
        gc_collect_cycles();
    }
}
echo "connection-bound objects release in every order\n";

$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t(i INTEGER)');
$appender = $conn->appender('t');
$appender->appendRow([7]);
unset($appender);
check($conn->query('SELECT i FROM t')->fetchAll() === [['i' => 7]], 'Destroyed appenders still flush');
$pending = $conn->queryPending('SELECT count(*) AS n FROM range(100000)');
unset($pending);
check($conn->query('SELECT 1 AS one')->fetchAll() === [['one' => 1]], 'Discarded pending queries free the connection');
echo "destruction keeps the connection usable\n";
?>
--EXPECT--
Connection is closed
connection-bound objects release in every order
destruction keeps the connection usable
