--TEST--
Selection vectors: construction, reading and validation
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

$selection = new DuckDB\SelectionVector([3, 0, 0, 7]);
check($selection instanceof Countable, 'SelectionVector is countable');
check($selection->count() === 4, 'count() reports the length');
check(count($selection) === 4, 'count() works through Countable');
check($selection->toArray() === [3, 0, 0, 7], 'toArray() returns the indices in order');
check($selection->get(0) === 3, 'get() reads the first position');
check($selection->get(3) === 7, 'get() reads the last position');
echo "selections read back their indices\n";

$empty = new DuckDB\SelectionVector([]);
check($empty->count() === 0, 'Empty selection has no entries');
check($empty->toArray() === [], 'Empty selection has no indices');
echo "empty selections are allowed\n";

$values = [2, 1];
foreach ($values as &$value) {
    $value = $value * 2;
}
unset($value);
$values[] = 0;
$referenced = [&$values[0], $values[1], $values[2]];
check((new DuckDB\SelectionVector($values))->toArray() === [4, 2, 0], 'Elements left as references are accepted');
check((new DuckDB\SelectionVector($referenced))->toArray() === [4, 2, 0], 'Reference elements are dereferenced');

$largest = new DuckDB\SelectionVector([4294967294, 0]);
check($largest->get(0) === 4294967294, 'The largest index is accepted');
echo "references and the largest index are accepted\n";

$messages = [];
$messages[] = rejects(fn() => $empty->get(0), ValueError::class);
$messages[] = rejects(fn() => $selection->get(-1), ValueError::class);
$messages[] = rejects(fn() => $selection->get(4), ValueError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([4294967295]), ValueError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([0, -1]), ValueError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([PHP_INT_MAX]), ValueError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector(['1']), TypeError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([1.0]), TypeError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([true]), TypeError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([null]), TypeError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector([1 => 0]), ValueError::class);
$messages[] = rejects(fn() => new DuckDB\SelectionVector(['a' => 1]), ValueError::class);
$messages[] = rejects(fn() => $selection->__construct([1]), Error::class);
check($selection->toArray() === [3, 0, 0, 7], 'A rejected re-initialization keeps the indices');
echo implode("\n", $messages), "\n";
?>
--EXPECT--
selections read back their indices
empty selections are allowed
references and the largest index are accepted
DuckDB\SelectionVector::get(): Argument #1 ($position) must be a position in a non-empty selection
DuckDB\SelectionVector::get(): Argument #1 ($position) must be between 0 and 3
DuckDB\SelectionVector::get(): Argument #1 ($position) must be between 0 and 3
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers between 0 and 4294967294
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers between 0 and 4294967294
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers between 0 and 4294967294
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must contain only integers
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must be a list
DuckDB\SelectionVector::__construct(): Argument #1 ($indices) must be a list
DuckDB\SelectionVector object is already initialized
