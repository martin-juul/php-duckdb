--TEST--
Standalone vectors: every type decodes like typed binding of the same input
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php

function normalize(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('normalize', $value);
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s.u e');
    }
    if (is_object($value)) {
        return get_class($value) . ':' . $value;
    }
    if (is_string($value)) {
        return bin2hex($value);
    }
    return $value;
}

$conn = (new DuckDB\Database())->connect();
$conn->query("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");
$select = $conn->prepare('SELECT $1 AS v');

$cases = [
    ['BOOLEAN', [true, false, 'true', 0]],
    ['TINYINT', [-128, 127, '5']],
    ['SMALLINT', [-32768, 32767]],
    ['INTEGER', [PHP_INT_MIN >> 32, 7, 7.0]],
    ['BIGINT', [PHP_INT_MIN, PHP_INT_MAX, '12']],
    ['UTINYINT', [0, 255]],
    ['USMALLINT', [65535]],
    ['UINTEGER', [4294967295]],
    ['UBIGINT', [PHP_INT_MAX, '18446744073709551615']],
    ['HUGEINT', ['-170141183460469231731687303715884105728', 1]],
    ['UHUGEINT', ['340282366920938463463374607431768211455']],
    ['BIGNUM', ['123456789012345678901234567890123456789012345678901234567890']],
    ['FLOAT', [1.5, -0.25, 3]],
    ['DOUBLE', [M_PI, -0.0, INF, 1]],
    ['DECIMAL(4,1)', ['123.4', 1.25, -9]],
    ['DECIMAL(18,6)', ['123456789012.123456']],
    ['DECIMAL(38,10)', ['1234567890123456789012345678.0123456789']],
    ['VARCHAR', ['', 'short', str_repeat('heap ', 10), "nul\0", 12]],
    ['BLOB', ["\x00\xff", '']],
    ['BIT', ['10110']],
    ['UUID', ['550e8400-e29b-41d4-a716-446655440000']],
    ['JSON', ['{"a":[1,2]}', '[]']],
    ['DATE', ['2024-02-29', new DateTimeImmutable('1999-12-31')]],
    ['TIME', ['12:34:56.789']],
    ['TIME_NS', ['12:34:56.123456789']],
    ['TIMETZ', ['12:34:56+02']],
    ['TIMESTAMP_S', ['2024-01-02 03:04:05']],
    ['TIMESTAMP_MS', ['2024-01-02 03:04:05.678']],
    ['TIMESTAMP', [new DateTimeImmutable('2024-01-02 03:04:05.123456')]],
    ['TIMESTAMP_NS', ['2024-01-02 03:04:05.123456789']],
    ['TIMESTAMPTZ', ['2024-01-02 03:04:05+00']],
    ['INTERVAL', [new DuckDB\Interval(1, 2, 3), '1 day']],
    ["ENUM('a', 'b')", ['b']],
    ['mood', ['happy']],
    ['INTEGER[]', [[1, null, 3], []]],
    ['TIMETZ[]', [['12:34:56+02', null]]],
    ['STRUCT(t TIMESTAMPTZ)', [['t' => '2024-01-02 03:04:05+00']]],
    ['VARCHAR[][]', [[['a'], [], null]]],
    ['INTEGER[3]', [[1, 2, 3]]],
    ['STRUCT(a INTEGER, "b c" VARCHAR[])', [['a' => 1, 'b c' => ['x']], ['a' => null, 'b c' => null]]],
    ['MAP(VARCHAR, INTEGER)', [[['key' => 'k', 'value' => 1]], []]],
    ['UNION(n INTEGER, s VARCHAR)', [['tag' => 's', 'value' => 'hi'], ['tag' => 'n', 'value' => 4]]],
    ['VARIANT', [1, 'text', ['k' => [1, 2]]]],
    ["GEOMETRY('OGC:CRS84')", ['POINT (1 2)', new DuckDB\Geometry('POINT (3 4)', 'OGC:CRS84')]],
];

$checked = 0;
foreach ($cases as [$type, $inputs]) {
    $count = count($inputs) + 1;
    $vector = $conn->createVector($type, $count);
    $vector->setValues($conn, [...$inputs, null]);
    $expected = [];
    foreach ($inputs as $input) {
        $expected[] = $select->bindValue(1, new DuckDB\Value($type, $input))->execute()->fetchColumn();
    }
    $expected[] = null;

    $actual = $vector->toArray();
    if (normalize($actual) !== normalize($expected)) {
        echo "$type mismatch\n";
        var_dump($actual, $expected);
    }
    foreach ($inputs as $row => $input) {
        $single = $conn->createVector($type, 1);
        $single->set($conn, 0, $input);
        if (normalize($single->get(0)) !== normalize($expected[$row])) {
            echo "$type single-row mismatch at $row\n";
        }
    }
    $checked += count($inputs);
}
echo "$checked inputs match typed binding\n";

$nested = $conn->createVector('STRUCT(s VARCHAR, l INTEGER[], a INTEGER[2])[]', 3);
$nested->set($conn, 1, [['s' => 'x', 'l' => [1], 'a' => [1, 2]], null]);
$nested->set($conn, 1, [['s' => 'y', 'l' => [], 'a' => [3, null]]]);
var_dump($nested->toArray());
echo $nested->type(), "\n";

// Nested time zone types previously rendered C API names in conversion SQL.
$nestedTz = $select->bindValue(1, new DuckDB\ListValue(['12:34:56+02'], 'TIMETZ'))->execute()->fetchColumn();
echo count($nestedTz), " nested TIMETZ value binds\n";

$crs = $conn->createVector("GEOMETRY('OGC:CRS84')", 1);
$schema = DuckDB\DataChunk::fromVectors(['g' => $crs], 1)->arrowSchema($conn)->toArray();
$metadata = array_column($schema['children'][0]['metadata'], 'value', 'key');
echo str_contains($metadata['ARROW:extension:metadata'], 'CRS84') ? "geometry CRS preserved\n" : "CRS lost\n";

$json = $conn->createVector('JSON', 1);
try {
    $json->set($conn, 0, '{bad');
} catch (DuckDB\ConversionException $error) {
    echo "JSON input is validated\n";
}
?>
--EXPECT--
79 inputs match typed binding
array(3) {
  [0]=>
  NULL
  [1]=>
  array(1) {
    [0]=>
    array(3) {
      ["s"]=>
      string(1) "y"
      ["l"]=>
      array(0) {
      }
      ["a"]=>
      array(2) {
        [0]=>
        int(3)
        [1]=>
        NULL
      }
    }
  }
  [2]=>
  NULL
}
STRUCT(s VARCHAR, l INTEGER[], a INTEGER[2])[]
1 nested TIMETZ value binds
geometry CRS preserved
JSON input is validated
