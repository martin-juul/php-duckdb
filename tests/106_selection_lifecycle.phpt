--TEST--
Selection vectors: guarded objects, ownership and repeated construction
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

$selection = new DuckDB\SelectionVector([1, 0]);
rejects(fn() => clone $selection, Error::class);
rejects(fn() => serialize($selection), Exception::class);
rejects(fn() => unserialize('O:22:"DuckDB\SelectionVector":0:{}'), Exception::class);
$reflection = new ReflectionClass(DuckDB\SelectionVector::class);
check($reflection->isFinal(), 'SelectionVector is final');
rejects(fn() => $reflection->newInstanceWithoutConstructor(), ReflectionException::class);
echo "selections reject cloning, serialization and invalid construction\n";

function build(): DuckDB\SelectionVector
{
    $db = new DuckDB\Database();
    $conn = $db->connect();
    $selection = new DuckDB\SelectionVector([2, 2, 0]);
    $conn->close();
    return $selection;
}

$owned = build();
gc_collect_cycles();
check($owned->toArray() === [2, 2, 0], 'Selection outlives every connection and database');
echo "selections need no connection\n";

for ($i = 0; $i < 200; $i++) {
    $loop = new DuckDB\SelectionVector(range(0, 63));
    check(count($loop) === 64, 'Loop selection has every index');
    rejects(fn() => new DuckDB\SelectionVector([0, 1, -1]), ValueError::class);
    rejects(fn() => new DuckDB\SelectionVector([0, '1']), TypeError::class);
    rejects(fn() => new DuckDB\SelectionVector([1 => 0]), ValueError::class);
    rejects(fn() => $loop->__construct([0]), Error::class);
}
echo "repeated selections release memory\n";
?>
--EXPECT--
selections reject cloning, serialization and invalid construction
selections need no connection
repeated selections release memory
