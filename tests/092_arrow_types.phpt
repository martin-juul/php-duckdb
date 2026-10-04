--TEST--
Arrow type families: lossless extension types, nullable composites and repeated native exports
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

$conn = (new DuckDB\Database())->connect();
$cases = [
    'BOOLEAN' => 'true',
    'TINYINT' => "'-128'",
    'SMALLINT' => "'-32768'",
    'INTEGER' => "'-2147483648'",
    'BIGINT' => "'-9223372036854775808'",
    'UTINYINT' => '255',
    'USMALLINT' => '65535',
    'UINTEGER' => '4294967295',
    'UBIGINT' => "'18446744073709551615'",
    'HUGEINT' => "'170141183460469231731687303715884105727'",
    'UHUGEINT' => "'340282366920938463463374607431768211455'",
    'FLOAT' => '1.5',
    'DOUBLE' => '2.25',
    'DECIMAL(4,2)' => "'-99.99'",
    'DECIMAL(9,4)' => "'12345.6789'",
    'DECIMAL(18,6)' => "'-123456789012.345678'",
    'DECIMAL(38,2)' => "'-999999999999999999999999999999999999.99'",
    'DATE' => "'2026-10-03'",
    'TIME' => "'12:34:56.123456'",
    'TIME_NS' => "'12:34:56.123456789'",
    'TIMETZ' => "'12:34:56+02'",
    'TIMESTAMP_S' => "'2026-10-03 12:34:56'",
    'TIMESTAMP_MS' => "'2026-10-03 12:34:56.123'",
    'TIMESTAMP' => "'2026-10-03 12:34:56.123456'",
    'TIMESTAMP_NS' => "'2026-10-03 12:34:56.123456789'",
    'TIMESTAMPTZ' => "'2026-10-03 12:34:56+02'",
    'INTERVAL' => "'2 months 3 days 4 seconds'",
    'VARCHAR' => "'héllo'",
    'BLOB' => "from_hex('610062ff')",
    'UUID' => "'550e8400-e29b-41d4-a716-446655440000'",
    'BIT' => "'00101'",
    'BIGNUM' => "'12345678901234567890123456789012345678901234567890'",
    'JSON' => "'{\"a\":[1,null]}'",
    'INTEGER[]' => '[1,NULL,3]',
    'INTEGER[3]' => '[1,NULL,3]',
    'STRUCT(a INTEGER,b VARCHAR[])' => "{'a':NULL,'b':['x',NULL]}",
    'MAP(VARCHAR,INTEGER)' => "map(['01','1'],[1,NULL])",
    'MAP(INTEGER[],VARCHAR)' => "map([[1,NULL],[]],['nested','empty'])",
    'UNION(i INTEGER,s VARCHAR)' => "union_value(s := 'text')",
    'GEOMETRY' => "'POINT (1 2)'",
    "GEOMETRY('OGC:CRS84')" => "'POINT (1 2)'",
    'SQLNULL' => 'NULL',
];

foreach ($cases as $type => $expression) {
    $value = $type === 'SQLNULL' ? $expression : '(' . $expression . ')::' . $type;
    $null = $type === 'SQLNULL' ? 'NULL' : 'NULL::' . $type;
    $sql = 'SELECT v FROM (VALUES (' . $value . '), (' . $null . ')) t(v)';
    $expected = $conn->query($sql)->fetchAll();
    $result = $conn->query($sql);
    $arrow = $result->fetchArrowChunk();
    unset($result);
    $native = $conn->dataChunkFromArrow($arrow);
    check($native->toRows() == $expected, $type . ': imported rows differ');
    check($native->toRows(DuckDB\FetchMode::Num) == array_map('array_values', $expected), $type . ': numeric rows differ');
    $again = $native->toArrow($conn);
    check($conn->dataChunkFromArrow($again)->toRows() == $expected, $type . ': repeated export differs');
}
echo "scalar widths, unsigned extremes, decimals and temporal precision roundtrip\n";
echo "binary values, extension types, nullable composites and SQLNULL roundtrip\n";

// Dictionary-backed vectors must decode selected labels, not dictionary storage order.
$sql = "SELECT CASE WHEN i % 3 = 0 THEN 'blue' WHEN i % 3 = 1 THEN 'red' ELSE 'green' END
    ::ENUM('red','green','blue') AS color FROM range(17) t(i)";
$expected = $conn->query($sql)->fetchAll();
$native = $conn->dataChunkFromArrow($conn->query($sql)->fetchArrowChunk());
check($native->toRows() === $expected, 'Enum dictionary indices differ');
check($conn->dataChunkFromArrow($native->toArrow($conn))->toRows() === $expected, 'Decoded enum re-export differs');
$sql = "SELECT union_value(s := NULL::VARCHAR)::UNION(i INTEGER,s VARCHAR) AS selected_null";
$expected = $conn->query($sql)->fetchAll();
check($conn->dataChunkFromArrow($conn->query($sql)->fetchArrowChunk())->toRows() === $expected, 'Selected UNION NULL loses tag');
echo "dictionary selections and selected UNION NULL preserve values\n";

$conn->query('SET arrow_lossless_conversion = false');
$cases = [
    ['UUID', "'550e8400-e29b-41d4-a716-446655440000'", 'VARCHAR'],
    ["ENUM('red','green')", "'green'", 'VARCHAR'],
    ['JSON', "'{\"a\":[1,null]}'", 'VARCHAR'],
];
foreach ($cases as [$type, $expression, $normalized]) {
    $sql = 'SELECT (' . $expression . ')::' . $type . ' AS v';
    $expectedSql = 'SELECT v::' . $normalized . ' AS v FROM (' . $sql . ') q';
    $expected = $conn->query($expectedSql)->fetchAll();
    $native = $conn->dataChunkFromArrow($conn->query($sql)->fetchArrowChunk());
    check($native->columns()[0]['type'] === $normalized, $type . ': unexpected default imported type');
    check($native->toRows() == $expected, $type . ': normalized value differs');
}
echo "explicit lossy exports preserve safe UUID, ENUM and JSON values\n";

$unsupportedSql = "SELECT 'text'::VARIANT AS v";
$expected = $conn->query($unsupportedSql)->fetchRow();
$unsupported = $conn->query($unsupportedSql);
foreach ([fn() => $unsupported->arrowSchema(), fn() => $unsupported->fetchArrowChunk()] as $operation) {
    try {
        $operation();
        throw new RuntimeException('Unsupported VARIANT export did not throw');
    } catch (DuckDB\Exception $error) {
        check(str_contains($error->getMessage(), 'VARIANT'), 'Unsupported export must identify its type');
    }
}
check($unsupported->fetchRow() === $expected, 'Unsupported export must retain unread row');
echo "unsupported Arrow types throw descriptive exceptions and retain unread rows\n";
?>
--EXPECT--
scalar widths, unsigned extremes, decimals and temporal precision roundtrip
binary values, extension types, nullable composites and SQLNULL roundtrip
dictionary selections and selected UNION NULL preserve values
explicit lossy exports preserve safe UUID, ENUM and JSON values
unsupported Arrow types throw descriptive exceptions and retain unread rows
