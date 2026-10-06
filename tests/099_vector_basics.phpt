--TEST--
Standalone vectors: NULL initialization, writes, reads, bounds and atomic conversion
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

function rejects(callable $operation, string $class = Throwable::class): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        check($error->getMessage() !== '', 'Exception must describe the failure');
        return $error->getMessage();
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();

check(DuckDB\vectorSize() === 2048, 'Pinned engine uses 2048-row vectors');
$default = $conn->createVector('INTEGER');
check($default->capacity() === DuckDB\vectorSize(), 'Capacity defaults to the vector size');
check(count($default->toArray()) === DuckDB\vectorSize(), 'toArray() reads through the capacity');

$ints = $conn->createVector('INTEGER', 4);
check($ints->type() === 'INTEGER' && $ints->capacity() === 4, 'Type and capacity');
check($ints->toArray() === [null, null, null, null], 'Every row starts NULL');
check($ints->isNull(0) && $ints->get(3) === null, 'NULL accessors');
echo "new vectors are NULL-initialized\n";

$ints->setValues($conn, [1, '2', null, 4]);
check($ints->toArray() === [1, 2, null, 4], 'Plain and converted values');
$ints->set($conn, 2, 3.0);
check($ints->get(2) === 3 && !$ints->isNull(2), 'Float input casts like SQL');
$ints->setNull(0);
check($ints->isNull(0) && $ints->toArray(1) === [2, 3, 4], 'setNull() and offset reads');
check($ints->toArray(1, 2) === [2, 3] && $ints->toArray(4) === [] && $ints->toArray(2, 0) === [], 'Ranges');
$ints->setValues($conn, [8, 9], 2);
check($ints->toArray() === [null, 2, 8, 9], 'setValues() writes from an offset');
$ints->set($conn, 0, new DuckDB\SmallInt(-5));
check($ints->get(0) === -5, 'Typed input casts to the vector type');
echo "values are written and decoded\n";

$before = $ints->toArray();
check(str_contains(rejects(fn() => $ints->set($conn, 1, 1 << 40), DuckDB\ConversionException::class),
    'out of range'), 'Engine reports range errors');
rejects(fn() => $ints->setValues($conn, [7, 'x', 7]), DuckDB\ConversionException::class);
check($ints->toArray() === $before, 'Rejected batches leave the vector unchanged');

$strings = $conn->createVector('VARCHAR', 3);
rejects(fn() => $strings->setValues($conn, ['ok', "\xff", 'z']), ValueError::class);
check($strings->toArray() === [null, null, null], 'Invalid UTF-8 rejects the whole batch');
$strings->setValues($conn, ['plain', "nul\0byte", str_repeat('long ', 20)]);
check($strings->toArray() === ['plain', "nul\0byte", str_repeat('long ', 20)], 'Inline, NUL and heap strings');
$strings->set($conn, 0, 42);
check($strings->get(0) === '42', 'Integers cast to VARCHAR');
echo "rejected writes are atomic\n";

$messages = [
    rejects(fn() => $ints->get(4), ValueError::class),
    rejects(fn() => $ints->get(-1), ValueError::class),
    rejects(fn() => $ints->isNull(4), ValueError::class),
    rejects(fn() => $ints->setNull(4), ValueError::class),
    rejects(fn() => $ints->set($conn, 4, 1), ValueError::class),
    rejects(fn() => $ints->setValues($conn, [1, 2], 3), ValueError::class),
    rejects(fn() => $ints->setValues($conn, [1], -1), ValueError::class),
    rejects(fn() => $ints->setValues($conn, ['a' => 1]), ValueError::class),
    rejects(fn() => $ints->toArray(5), ValueError::class),
    rejects(fn() => $ints->toArray(0, 5), ValueError::class),
    rejects(fn() => $ints->toArray(0, -1), ValueError::class),
    rejects(fn() => $conn->createVector('INTEGER', -1), ValueError::class),
    rejects(fn() => $conn->createVector('INTEGER', 1 << 32), ValueError::class),
];
echo $messages[0], "\n", $messages[7], "\n";
rejects(fn() => $conn->createVector('NOT_A_TYPE'), DuckDB\CatalogException::class);
rejects(fn() => $conn->createVector('INTEGER)'), ValueError::class);
rejects(fn() => $conn->createVector(42), TypeError::class);
check($ints->toArray() === $before, 'Bounds errors leave the vector unchanged');

$empty = $conn->createVector('VARCHAR', 0);
check($empty->capacity() === 0 && $empty->toArray() === [], 'Empty vectors');
$empty->setValues($conn, []);
echo rejects(fn() => $empty->get(0), ValueError::class), "\n";
echo "bounds are checked\n";

$fromClass = $conn->createVector(DuckDB\UTinyInt::class, 2);
$fromValue = $conn->createVector(new DuckDB\Decimal(null, 10, 2), 1);
check($fromClass->type() === 'UTINYINT' && $fromValue->type() === 'DECIMAL(10,2)', 'Type specifications');
$fromClass->setValues($conn, [255, 0]);
rejects(fn() => $fromClass->set($conn, 0, 256), DuckDB\ConversionException::class);
rejects(fn() => $fromClass->set($conn, 0, -1), DuckDB\ConversionException::class);
check($fromClass->toArray() === [255, 0], 'Unsigned range checks');
echo "type specifications resolve\n";
?>
--EXPECT--
new vectors are NULL-initialized
values are written and decoded
rejected writes are atomic
DuckDB\Vector::get(): Argument #1 ($index) must be between 0 and 3
DuckDB\Vector::setValues(): Argument #2 ($values) must be a list
DuckDB\Vector::get(): Argument #1 ($index) must be a row of a non-empty vector
bounds are checked
type specifications resolve
