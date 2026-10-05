--TEST--
Standalone vectors: guarded objects, ownership, closed connections, streams and transactions
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

function rejects(callable $operation, string $class = Throwable::class): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        check($error->getMessage() !== '', 'Exception must describe the failure');
        return;
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();
$vector = $conn->createVector('VARCHAR', 2);
rejects(fn() => clone $vector, Error::class);
rejects(fn() => serialize($vector), Exception::class);
rejects(fn() => unserialize('O:13:"DuckDB\Vector":0:{}'), Exception::class);
rejects(fn() => new DuckDB\Vector(), Error::class);
$reflection = new ReflectionClass(DuckDB\Vector::class);
check($reflection->isFinal(), 'Vector is final');
try {
    $uninitialized = $reflection->newInstanceWithoutConstructor();
    rejects(fn() => $uninitialized->capacity(), Error::class);
    rejects(fn() => DuckDB\DataChunk::fromVectors(['v' => $uninitialized], 0), Error::class);
    rejects(fn() => $vector->copyFrom($uninitialized), Error::class);
} catch (ReflectionException $error) {
}
rejects(fn() => new DuckDB\DataChunk(), Error::class);
echo "vectors reject cloning, serialization and invalid construction\n";

function build(): DuckDB\Vector
{
    $db = new DuckDB\Database();
    $conn = $db->connect();
    $conn->query("CREATE TYPE level AS ENUM ('low', 'high')");
    $vector = $conn->createVector('STRUCT(l level, tags VARCHAR[])', 2);
    $vector->set($conn, 0, ['l' => 'high', 'tags' => [str_repeat('x', 40)]]);
    $conn->close();
    return $vector;
}

$owned = build();
gc_collect_cycles();
check($owned->get(0) === ['l' => 'high', 'tags' => [str_repeat('x', 40)]], 'Vector outlives its database');
$other = (new DuckDB\Database())->connect();
$owned->set($other, 1, ['l' => 'low', 'tags' => []]);
check($owned->toArray() === [['l' => 'high', 'tags' => [str_repeat('x', 40)]], ['l' => 'low', 'tags' => []]],
    'Another database converts input for the resolved type');
$chunk = DuckDB\DataChunk::fromVectors(['v' => $owned], 2);
unset($owned);
check(count($chunk->toRows()) === 2, 'Chunks outlive their vectors');
echo "vectors own their memory\n";

$closed = (new DuckDB\Database())->connect();
$closed->close();
rejects(fn() => $closed->createVector('INTEGER'), DuckDB\ConnectionException::class);
rejects(fn() => $vector->set($closed, 0, 'x'), DuckDB\ConnectionException::class);
rejects(fn() => $vector->setValues($closed, ['x']), DuckDB\ConnectionException::class);
check($vector->toArray() === [null, null], 'Closed connections leave vectors unchanged');
$vector->setNull(0);
echo "closed connections are rejected\n";

$stream = $conn->queryStreaming('SELECT * FROM range(5000) t(i)');
$stream->fetchRow();
$separate = (new DuckDB\Database())->connect();
$separate->createVector('INTEGER', 1)->set($separate, 0, '1');
check($stream->fetchRow() === ['i' => 1], 'Other connections leave streams intact');
$conn->createVector('INTEGER', 1);
rejects(function () use ($stream) {
    while ($stream->fetchRow() !== null) {
    }
}, DuckDB\Exception::class);
echo "type resolution invalidates the connection's stream\n";

$conn->query('CREATE TABLE t(i INTEGER)');
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1)');
$ints = $conn->createVector('INTEGER', 2);
rejects(fn() => $ints->set($conn, 0, 'nope'), DuckDB\ConversionException::class);
$ints->set($conn, 1, '2');
$conn->commit();
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 1]], 'Transaction remains usable');
check($ints->toArray() === [null, 2], 'Writes inside transactions');
echo "conversion errors keep transactions usable\n";

for ($i = 0; $i < 200; $i++) {
    $loop = $conn->createVector('STRUCT(s VARCHAR, l VARCHAR[])[]', 4);
    $loop->setValues($conn, [[['s' => str_repeat('y', 30), 'l' => ['a', str_repeat('z', 30)]]], null, [], [null]]);
    $loop->copyFrom($loop, 0, 2, 2);
    DuckDB\DataChunk::fromVectors(['x' => $loop], 4)->vector(0)->toArray();
}
echo "repeated nested vectors release memory\n";
?>
--EXPECT--
vectors reject cloning, serialization and invalid construction
vectors own their memory
closed connections are rejected
type resolution invalidates the connection's stream
conversion errors keep transactions usable
repeated nested vectors release memory
