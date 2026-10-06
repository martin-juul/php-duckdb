--TEST--
Selection vectors: selecting and gathering vector rows
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
        check($error->getMessage() !== '', 'Exception must describe the failure');
        return $error->getMessage();
    }

    throw new RuntimeException('Expected rejection');
}

function normalize(mixed $value): mixed
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s.u e');
    }
    if ($value instanceof DuckDB\Interval) {
        return json_encode($value);
    }
    if (is_array($value)) {
        return array_map('normalize', $value);
    }

    return $value;
}

/** @param int[] $indices */
function pick(array $rows, array $indices): array
{
    return array_map(fn(int $index) => $rows[$index], $indices);
}

$conn = (new DuckDB\Database())->connect();

$ints = $conn->createVector('INTEGER', 3);
$ints->setValues($conn, [10, 20, 30]);
$picked = $ints->select([2, 0, 0]);
check($picked->capacity() === 3, 'The result capacity is the selection length');
check($picked->toArray() === [30, 10, 10], 'Rows follow the selection order');
check($picked->type() === 'INTEGER', 'The result keeps the source type');
check($ints->toArray() === [10, 20, 30], 'The source is unchanged');

$single = $conn->createVector('VARCHAR', 1);
$single->set($conn, 0, 'only');
check($single->select([0, 0, 0, 0])->toArray() === ['only', 'only', 'only', 'only'],
    'A selection may be longer than its source');

$nulls = $conn->createVector('INTEGER', 3);
$nulls->setValues($conn, [1, null, 3]);
$withNull = $nulls->select([1, 2, 1]);
check($withNull->toArray() === [null, 3, null], 'NULL rows stay NULL');
check($withNull->isNull(0) && !$withNull->isNull(1), 'isNull() agrees with the selected rows');
echo "select() gathers rows into a new vector\n";

$cases = [
    ['VARCHAR', ['', 'short', str_repeat('heap ', 10), null]],
    ['BLOB', ["\x00\xff", '', str_repeat("\x01", 40), null]],
    ['DECIMAL(18,6)', ['123456789012.123456', '-1.5', null, '0']],
    ["ENUM('low', 'high')", ['high', null, 'low', 'low']],
    ['UUID', ['550e8400-e29b-41d4-a716-446655440000', null, '00000000-0000-0000-0000-000000000001', null]],
    ['TIMESTAMPTZ', ['2024-01-02 03:04:05+00', null, '1999-12-31 23:59:59+00', '2000-01-01 00:00:00+00']],
    ['INTERVAL', [new DuckDB\Interval(1, 2, 3), null, '1 day', '2 hours']],
    ['VARCHAR[]', [['a', str_repeat('b', 30)], [], null, [null, 'c']]],
    ['STRUCT(a INTEGER, "b c" VARCHAR[])', [['a' => 1, 'b c' => ['x']], null, ['a' => null, 'b c' => null], ['a' => 4, 'b c' => []]]],
    ['MAP(VARCHAR, INTEGER)', [[['key' => 'k', 'value' => 1]], [], null, [['key' => 'x', 'value' => 2], ['key' => 'y', 'value' => null]]]],
    ['UNION(n INTEGER, s VARCHAR)', [['tag' => 's', 'value' => 'hi'], ['tag' => 'n', 'value' => 4], null, ['tag' => 's', 'value' => str_repeat('u', 20)]]],
    ['INTEGER[2]', [[1, 2], null, [3, null], [5, 6]]],
    ['STRUCT(s VARCHAR, l INTEGER[], a INTEGER[2])[]', [[['s' => 'x', 'l' => [1], 'a' => [1, 2]]], null, [], [null]]],
];
$order = new DuckDB\SelectionVector([3, 1, 0, 2, 3]);
foreach ($cases as [$type, $inputs]) {
    $vector = $conn->createVector($type, count($inputs));
    $vector->setValues($conn, $inputs);
    $expected = normalize(pick($vector->toArray(), $order->toArray()));
    check(normalize($vector->select($order)->toArray()) === $expected, "$type selection matches its rows");
    check(normalize($vector->select($order->toArray())->toArray()) === $expected, "$type list selection matches");
}
echo count($cases), " types select through objects and lists\n";

$empty = $ints->select([]);
check($empty->capacity() === 0 && $empty->toArray() === [], 'An empty selection gives an empty vector');
$none = $conn->createVector('INTEGER', 0);
check($none->select([])->capacity() === 0, 'An empty source accepts an empty selection');
echo "empty selections give empty vectors\n";

$target = $conn->createVector('INTEGER', 5);
$target->setValues($conn, [0, 0, 0, 0, 0]);
$target->copySelected($ints, [2, 0], 1);
check($target->toArray() === [0, 30, 10, 0, 0], 'copySelected() writes from the target offset');
$target->copySelected($ints, new DuckDB\SelectionVector([1]));
check($target->toArray() === [20, 30, 10, 0, 0], 'The target offset defaults to 0');
$target->copySelected($ints, []);
check($target->toArray() === [20, 30, 10, 0, 0], 'An empty gather changes nothing');
$zero = $conn->createVector('INTEGER', 0);
$zero->copySelected($ints, [], 0);
echo "copySelected() gathers into an existing vector\n";

$swaps = [
    ['INTEGER', [1, 2, 3]],
    ['VARCHAR', [str_repeat('first ', 8), 'second', null]],
    ['VARCHAR[]', [['a'], ['b', str_repeat('c', 30)], []]],
    ['STRUCT(a INTEGER, b VARCHAR[])', [['a' => 1, 'b' => ['x']], ['a' => 2, 'b' => []], null]],
    ['MAP(VARCHAR, INTEGER)', [[['key' => 'k', 'value' => 1]], [['key' => 'm', 'value' => 2]], []]],
    ['UNION(n INTEGER, s VARCHAR)', [['tag' => 'n', 'value' => 1], ['tag' => 's', 'value' => 'two'], null]],
    ['INTEGER[2]', [[1, 2], [3, 4], null]],
];
foreach ($swaps as [$type, $inputs]) {
    $vector = $conn->createVector($type, count($inputs));
    $vector->setValues($conn, $inputs);
    $rows = $vector->toArray();
    $populated = $conn->createVector($type, 4);
    $populated->setValues($conn, [$inputs[1], $inputs[0], $inputs[1], $inputs[0]]);
    $kept = $populated->toArray();
    $populated->copySelected($vector, [2, 0], 1);
    check($populated->toArray() === [$kept[0], $rows[2], $rows[0], $kept[3]], "$type gather fills an offset range");
    $vector->copySelected($vector, [1, 0]);
    check($vector->toArray() === [$rows[1], $rows[0], $rows[2]], "$type self-gather swaps rows");
    $vector->copySelected($vector, [0, 0, 0]);
    check($vector->toArray() === [$rows[1], $rows[1], $rows[1]], "$type self-gather repeats rows");
}
echo count($swaps), " types gather into offsets and from themselves\n";

$owned = (function (): DuckDB\Vector {
    $db = new DuckDB\Database();
    $conn = $db->connect();
    $source = $conn->createVector('VARCHAR[]', 2);
    $source->setValues($conn, [['kept', str_repeat('k', 30)], ['dropped']]);
    $selected = $source->select([0, 0]);
    $conn->close();
    return $selected;
})();
gc_collect_cycles();
check($owned->toArray() === [['kept', str_repeat('k', 30)], ['kept', str_repeat('k', 30)]],
    'A selected vector outlives its source and connection');
echo "selected vectors own their memory\n";

for ($i = 0; $i < 200; $i++) {
    $loop = $conn->createVector('STRUCT(s VARCHAR, l VARCHAR[])[]', 3);
    $loop->setValues($conn, [[['s' => str_repeat('y', 30), 'l' => ['a', str_repeat('z', 30)]]], null, []]);
    $loop->select([2, 0, 0, 1])->toArray();
    $loop->copySelected($loop, [0, 0], 1);
    rejects(fn() => $loop->select([3]), ValueError::class);
}
echo "repeated selections release memory\n";

$bigint = $conn->createVector('BIGINT', 2);
$decimal = $conn->createVector('DECIMAL(12,2)', 2);
$narrow = $conn->createVector('DECIMAL(10,2)', 2);
$four = $conn->createVector('INTEGER', 4);
$four->setValues($conn, [1, 2, 3, 4]);
$before = $target->toArray();
$messages = [];
$messages[] = rejects(fn() => $four->select([0, 4]), ValueError::class);
$messages[] = rejects(fn() => $none->select([0]), ValueError::class);
$messages[] = rejects(fn() => $four->select('0,1'), TypeError::class);
$messages[] = rejects(fn() => $four->select($ints), TypeError::class);
$messages[] = rejects(fn() => $four->select([1 => 0]), ValueError::class);
$messages[] = rejects(fn() => $four->select(['0']), TypeError::class);
$messages[] = rejects(fn() => $four->select([-1]), ValueError::class);
$messages[] = rejects(fn() => $target->copySelected($four, [0, 1, 2], 3), ValueError::class);
$messages[] = rejects(fn() => $target->copySelected($four, [0], -1), ValueError::class);
$messages[] = rejects(fn() => $zero->copySelected($ints, [], 1), ValueError::class);
$messages[] = rejects(fn() => $target->copySelected($four, [4]), ValueError::class);
$messages[] = rejects(fn() => $target->copySelected($four, 'x'), TypeError::class);
$messages[] = rejects(fn() => $bigint->copySelected($ints, [0]), TypeError::class);
$messages[] = rejects(fn() => $decimal->copySelected($narrow, [0]), TypeError::class);
check($target->toArray() === $before, 'Rejected gathers leave the target unchanged');
echo implode("\n", $messages), "\n";
?>
--EXPECT--
select() gathers rows into a new vector
13 types select through objects and lists
empty selections give empty vectors
copySelected() gathers into an existing vector
7 types gather into offsets and from themselves
selected vectors own their memory
repeated selections release memory
DuckDB\Vector::select(): Argument #1 ($selection) must contain only indices less than 4, 4 given
DuckDB\Vector::select(): Argument #1 ($selection) must be empty for an empty source
DuckDB\Vector::select(): Argument #1 ($selection) must be of type DuckDB\SelectionVector|array, string given
DuckDB\Vector::select(): Argument #1 ($selection) must be of type DuckDB\SelectionVector|array, DuckDB\Vector given
DuckDB\Vector::select(): Argument #1 ($selection) must be a list
DuckDB\Vector::select(): Argument #1 ($selection) must contain only integers
DuckDB\Vector::select(): Argument #1 ($selection) must contain only integers between 0 and 4294967294
DuckDB\Vector::copySelected(): Argument #3 ($targetOffset) must leave the range within the vector capacity of 5
DuckDB\Vector::copySelected(): Argument #3 ($targetOffset) must leave the range within the vector capacity of 5
DuckDB\Vector::copySelected(): Argument #3 ($targetOffset) must leave the range within the vector capacity of 0
DuckDB\Vector::copySelected(): Argument #2 ($selection) must contain only indices less than 4, 4 given
DuckDB\Vector::copySelected(): Argument #2 ($selection) must be of type DuckDB\SelectionVector|array, string given
DuckDB\Vector::copySelected(): Argument #1 ($source) must have type BIGINT, INTEGER given
DuckDB\Vector::copySelected(): Argument #1 ($source) must have type DECIMAL(12,2), DECIMAL(10,2) given
