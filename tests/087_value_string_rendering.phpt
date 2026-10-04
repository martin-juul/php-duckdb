--TEST--
Value string rendering: DuckDB display text for scalar and composite types without losing binary strings
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
declare(strict_types=1);

$conn = (new DuckDB\Database())->connect();
$conn->query('SET threads = 1');
$conn->query("SET TimeZone = 'UTC'");

function renderingCheck(DuckDB\Connection $conn, DuckDB\Value $value, string $expression): void
{
    // Independent SQL literals supply the oracle, rather than fetching the
    // original typed parameter through PHP's potentially lossy result mapping.
    $type = $value->getType();
    $expected = $conn->query("SELECT COALESCE(CAST(CAST($expression AS $type) AS VARCHAR), 'NULL') AS v")
        ->fetchRow()['v'];
    $actual = $value->toString($conn);
    if ($actual !== $expected) {
        throw new RuntimeException($type . ': expected ' . bin2hex($expected) . ', got ' . bin2hex($actual));
    }
}

$scalarCases = [
    ['Boolean', 'BOOLEAN', true, 'true'],
    ['TinyInt', 'TINYINT', -128, '-128'],
    ['SmallInt', 'SMALLINT', -32768, '-32768'],
    ['Integer', 'INTEGER', 42, '42'],
    ['BigInt', 'BIGINT', '-9223372036854775808', "'-9223372036854775808'"],
    ['UTinyInt', 'UTINYINT', 255, '255'],
    ['USmallInt', 'USMALLINT', 65535, '65535'],
    ['UInteger', 'UINTEGER', '4294967295', "'4294967295'"],
    ['UBigInt', 'UBIGINT', '18446744073709551615', "'18446744073709551615'"],
    ['HugeInt', 'HUGEINT', '170141183460469231731687303715884105727', "'170141183460469231731687303715884105727'"],
    ['UHugeInt', 'UHUGEINT', '340282366920938463463374607431768211455', "'340282366920938463463374607431768211455'"],
    ['BigNum', 'BIGNUM', '12345678901234567890123456789012345678901234567890', "'12345678901234567890123456789012345678901234567890'"],
    ['Float32', 'FLOAT', 1.5, '1.5'],
    ['Double', 'DOUBLE', 2.25, '2.25'],
    ['Varchar', 'VARCHAR', "don't", "'don''t'"],
    ['Blob', 'BLOB', "a\0b", "from_hex('610062')"],
    ['Bit', 'BIT', '00101', "'00101'"],
    ['Uuid', 'UUID', '550e8400-e29b-41d4-a716-446655440000', "'550e8400-e29b-41d4-a716-446655440000'"],
    ['Json', 'JSON', '{"a":[1,null]}', "'{\"a\":[1,null]}'"],
    ['Date', 'DATE', '2026-10-03', "'2026-10-03'"],
    ['Time', 'TIME', '12:34:56.123456', "'12:34:56.123456'"],
    ['TimeNs', 'TIME_NS', '12:34:56.123456789', "'12:34:56.123456789'"],
    ['TimeTz', 'TIMETZ', '12:34:56+02:00', "'12:34:56+02:00'"],
    ['TimestampS', 'TIMESTAMP_S', '2026-10-03 12:34:56', "'2026-10-03 12:34:56'"],
    ['TimestampMs', 'TIMESTAMP_MS', '2026-10-03 12:34:56.123', "'2026-10-03 12:34:56.123'"],
    ['Timestamp', 'TIMESTAMP', '2026-10-03 12:34:56.123456', "'2026-10-03 12:34:56.123456'"],
    ['TimestampNs', 'TIMESTAMP_NS', '2026-10-03 12:34:56.123456789', "'2026-10-03 12:34:56.123456789'"],
    ['TimestampTz', 'TIMESTAMPTZ', '2026-10-03 12:34:56+02:00', "'2026-10-03 12:34:56+02:00'"],
    ['IntervalValue', 'INTERVAL', new DuckDB\Interval(2, 3, 4000000), "'2 months 3 days 4 seconds'"],
    ['Variant', 'VARIANT', new DuckDB\Json('{"a":1}'), "'{\"a\":1}'::JSON::VARIANT"],
];

foreach ($scalarCases as [$name, $type, $input, $expression]) {
    $class = 'DuckDB\\' . $name;
    renderingCheck($conn, new $class($input), $expression);
    renderingCheck($conn, new $class(null), 'NULL');
}
echo "scalar families and typed NULL: ok\n";

$cases = [
    [new DuckDB\Decimal('12.345', 18, 2), '12.345'],
    [new DuckDB\Decimal('-999999999999999999999999999999999999.99', 38, 2), "'-999999999999999999999999999999999999.99'"],
    [new DuckDB\Enum("a'b", ["a'b", 'c']), "'a''b'"],
    [new DuckDB\ListValue([], 'INTEGER'), '[]'],
    [new DuckDB\ListValue([null, null], 'INTEGER'), '[NULL,NULL]'],
    [new DuckDB\ListValue([new DuckDB\Integer(7), null], 'INTEGER'), '[7,NULL]'],
    [new DuckDB\ArrayValue([1, null], 'INTEGER', 2), '[1,NULL]'],
    [new DuckDB\Struct(['b' => 'x', 'a' => [1, null]], ['a' => 'INTEGER[]', 'b' => 'VARCHAR']), "{'a':[1,NULL],'b':'x'}"],
    [new DuckDB\Map([], 'VARCHAR', 'INTEGER'), 'map([]::VARCHAR[],[]::INTEGER[])'],
    [new DuckDB\Map([['key' => '01', 'value' => 1], ['key' => '1', 'value' => null]], 'VARCHAR', 'INTEGER'), "map(['01','1'],[1,NULL])"],
    [new DuckDB\Map([['key' => [1, 2], 'value' => 'first'], ['key' => [], 'value' => 'empty']], 'INTEGER[]', 'VARCHAR'), "map([[1,2],[]],['first','empty'])"],
    [new DuckDB\Union('text', 's', ['i' => 'INTEGER', 's' => 'VARCHAR']), "union_value(s := 'text')"],
    [new DuckDB\Union(null, 's', ['i' => 'INTEGER', 's' => 'VARCHAR']), 'union_value(s := NULL::VARCHAR)'],
    [new DuckDB\Union(null, null, ['i' => 'INTEGER', 's' => 'VARCHAR']), 'NULL'],
    [new DuckDB\Variant('plain'), "'plain'::VARIANT"],
    [new DuckDB\Variant([1, 'two', null]), "'[1,\"two\",null]'::JSON::VARIANT"],
    [new DuckDB\Variant(['a' => new DuckDB\UTinyInt(7), 'b' => new DuckDB\ListValue([], 'INTEGER')]), "{'a':7::UTINYINT,'b':[]::INTEGER[]}::VARIANT"],
    [new DuckDB\Geometry('POINT (1 2)'), "'POINT (1 2)'"],
    [new DuckDB\Geometry(new DuckDB\Blob(hex2bin('0101000000000000000000F03F0000000000000040')), 'OGC:CRS84'), "'POINT (1 2)'"],
    [new DuckDB\Date('infinity'), "'infinity'"],
    [new DuckDB\Timestamp('-infinity'), "'-infinity'"],
    [new DuckDB\Double(INF), "'Infinity'"],
    [new DuckDB\Double(NAN), "'NaN'"],
    [new DuckDB\Value('INT4', '7'), '7'],
];
foreach ($cases as [$value, $expression]) {
    renderingCheck($conn, $value, $expression);
    renderingCheck($conn, new DuckDB\Value($value->getType(), null), 'NULL');
}
$selectedNull = new DuckDB\Union(null, 's', ['i' => 'INTEGER', 's' => 'VARCHAR']);
$selectedNull->toString($conn);
$state = $conn->execute("SELECT union_tag(\$v) = 's' AND union_extract(\$v, 's') IS NULL AS ok", ['v' => $selectedNull]);
if ($state->fetchRow()['ok'] !== true) {
    throw new RuntimeException('Rendering changed the selected UNION NULL member');
}
echo "parameterized types, composites and special values: ok\n";

$binary = "a\0b'\\\n🦆";
$binarySQL = "'a' || chr(0) || 'b''\\' || chr(10) || '🦆'";
renderingCheck($conn, new DuckDB\Varchar($binary), $binarySQL);
renderingCheck($conn, new DuckDB\ListValue([$binary, ''], 'VARCHAR'), "[$binarySQL,'']");
renderingCheck($conn, new DuckDB\Struct(['text' => $binary], ['text' => 'VARCHAR']), "struct_pack(text := $binarySQL)");
renderingCheck($conn, new DuckDB\Variant($binary), "($binarySQL)::VARIANT");
if ((new DuckDB\Varchar("a\0b"))->toString($conn) !== "a\0b") {
    throw new RuntimeException('VARCHAR display truncated at NUL');
}
if ((new DuckDB\Varchar("don't"))->toString($conn) !== "don't"
    || (new DuckDB\Varchar(''))->toString($conn) !== '') {
    throw new RuntimeException('Display text was quoted as a SQL literal');
}
$large = str_repeat('9', 1000);
if ((new DuckDB\BigNum($large))->toString($conn) !== $large
    || (new DuckDB\BigNum('-' . $large))->toString($conn) !== '-' . $large) {
    throw new RuntimeException('Large BIGNUM lost precision');
}
echo "binary-safe display text and exact large integers: ok\n";
?>
--EXPECT--
scalar families and typed NULL: ok
parameterized types, composites and special values: ok
binary-safe display text and exact large integers: ok
