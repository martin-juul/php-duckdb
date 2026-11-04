--TEST--
PHP scalar functions: direct primitive result writes match typed conversion across batches
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

function cause(callable $operation): Throwable
{
    try {
        $operation();
    } catch (DuckDB\Exception $error) {
        check($error->getPrevious() !== null, 'Conversion failure keeps its cause');
        return $error->getPrevious();
    }
    throw new RuntimeException('Expected the query to fail');
}

final class Returns implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function __construct(private Closure $value)
    {
    }

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        $out = [];
        foreach ($arguments->toRows(DuckDB\FetchMode::Num) as [$row]) {
            $out[] = ($this->value)($row);
        }
        return $out;
    }
}

$conn = (new DuckDB\Database())->connect();
function register(DuckDB\Connection $conn, string $name, string $type, array $values): void
{
    $conn->registerScalarFunction($name, ['BIGINT'], $type, new Returns(fn(int $row) => $values[$row]));
}

function column(DuckDB\Connection $conn, string $name, int $rows): array
{
    $result = $conn->query("SELECT $name(i) FROM range($rows) t(i) ORDER BY i");
    return array_column($result->fetchAll(DuckDB\FetchMode::Num), 0);
}

/* Plain PHP scalars of the declared type, NULLs, and values that still need
 * DuckDB's cast, mixed in one batch. */
$cases = [
    ['php_bigint', 'BIGINT', [1, null, '7', 2.5, -2.5, true, PHP_INT_MAX, PHP_INT_MIN],
        [1, null, 7, 2, -2, 1, PHP_INT_MAX, PHP_INT_MIN]],
    ['php_integer', 'INTEGER', [-2147483648, 2147483647, null, 2.5], [-2147483648, 2147483647, null, 2]],
    ['php_utinyint', 'UTINYINT', [0, 255, '3', null], [0, 255, 3, null]],
    ['php_double', 'DOUBLE', [1.5, 2, '2.25', null, INF], [1.5, 2.0, 2.25, null, INF]],
    ['php_boolean', 'BOOLEAN', [true, false, 1, 'f', null], [true, false, true, false, null]],
    ['php_varchar', 'VARCHAR', ['duck', '', 42, null, true, "\u{1F986}", "a\0b"],
        ['duck', '', '42', null, 'true', "\u{1F986}", "a\0b"]],
    ['php_blob', 'BLOB', ["\x00\xff", '', null], ["\x00\xff", '', null]],
];
foreach ($cases as [$name, $type, $returned, $expected]) {
    register($conn, $name, $type, $returned);
    check(column($conn, $name, count($returned)) === $expected, "$type results");
    echo "$type\n";
}

/* Values outside a primitive type's range, or not valid for it, keep
 * DuckDB's own conversion error. */
$failures = [
    ['php_integer_overflow', 'INTEGER', 2147483648, DuckDB\ConversionException::class,
        'Type INT64 with value 2147483648 can\'t be cast because the value is out of range for the destination type INT32'],
    ['php_utinyint_negative', 'UTINYINT', -1, DuckDB\ConversionException::class,
        'Type INT64 with value -1 can\'t be cast because the value is out of range for the destination type UINT8'],
    ['php_bigint_text', 'BIGINT', 'x', DuckDB\ConversionException::class, 'Could not convert string \'x\' to INT64'],
    ['php_varchar_invalid', 'VARCHAR', "\xff", ValueError::class, 'PHP input conversion failed'],
];
foreach ($failures as [$name, $type, $value, $class, $message]) {
    register($conn, $name, $type, [null, $value]);
    $error = cause(fn() => column($conn, $name, 2));
    check($error instanceof $class, "$type failure class");
    check(str_contains($error->getMessage(), $message), "$type failure message: " . $error->getMessage());
    echo "$type failure\n";
}

/* Full batches convert through the same statement text, with different
 * parameter types at each position. A failed conversion in one of them
 * leaves the next query correct. */
$rows = 5000;
$failAt = -1;
$conn->registerScalarFunction('php_decimal', ['BIGINT'], 'DECIMAL(18,2)', new Returns(
    function (int $row) use (&$failAt) {
        if ($row === $failAt) {
            return 'abc';
        }
        return match ($row % 3) {
            0 => "$row.25",
            1 => $row,
            2 => $row % 2 === 0 ? null : $row + 0.5,
        };
    }
));
$expected = [];
for ($row = 0; $row < $rows; $row++) {
    $expected[] = match ($row % 3) {
        0 => "$row.25",
        1 => "$row.00",
        2 => $row % 2 === 0 ? null : "$row.50",
    };
}
check(column($conn, 'php_decimal', $rows) === $expected, 'DECIMAL batches');
$failAt = 3000;
$error = cause(fn() => column($conn, 'php_decimal', $rows));
check($error instanceof DuckDB\ConversionException, 'DECIMAL failure class');
check(str_contains($error->getMessage(), 'Could not convert string "abc" to DECIMAL(18,2)'),
    'DECIMAL failure message: ' . $error->getMessage());
$failAt = -1;
check(column($conn, 'php_decimal', $rows) === $expected, 'DECIMAL batches after a failure');
echo "DECIMAL batches\n";

/* Mixed direct and converted rows land in their own positions. */
$conn->registerScalarFunction('php_mixed', ['BIGINT'], 'BIGINT', new Returns(
    fn(int $row) => match ($row % 3) {
        0 => $row,
        1 => (string) $row,
        2 => null,
    }
));
$expected = [];
for ($row = 0; $row < $rows; $row++) {
    $expected[] = $row % 3 === 2 ? null : $row;
}
check(column($conn, 'php_mixed', $rows) === $expected, 'Mixed rows');
echo "mixed rows\n";
?>
--EXPECT--
BIGINT
INTEGER
UTINYINT
DOUBLE
BOOLEAN
VARCHAR
BLOB
INTEGER failure
UTINYINT failure
BIGINT failure
VARCHAR failure
DECIMAL batches
mixed rows
