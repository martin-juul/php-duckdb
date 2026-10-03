--TEST--
Native type-specific value classes: complete coverage, type specifications, immutability and ingestion
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
/** Native value classes require no PHP adapter or autoloader. */
declare(strict_types=1);
$conn = (new DuckDB\Database())->connect();
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
$cases = [];
foreach ($scalarCases as [$short, $type, $input, $literal]) {
    $class = 'DuckDB\\' . $short;
    if (!class_exists($class, false) || !(new ReflectionClass($class))->isInternal()
        || !(new ReflectionClass($class))->isFinal()) {
        throw new RuntimeException("Native final class unavailable: $class");
    }
    $value = new $class($input);
    if ($value->getType() !== $type || (new $class(null))->getType() !== $type) {
        throw new RuntimeException("Declared type mismatch: $class");
    }
    $cases[] = [$short, $value, $literal];
}
$cases = array_merge($cases, [
    ['Decimal', new DuckDB\Decimal('12.345', 18, 2), '12.35'],
    ['Enum', new DuckDB\Enum("a'b", ["a'b", 'c']), "'a''b'"],
    ['ListValue', new DuckDB\ListValue([], DuckDB\Integer::class), '[]'],
    ['ArrayValue', new DuckDB\ArrayValue([1, null], 'INTEGER', 2), '[1,NULL]'],
    ['Struct', new DuckDB\Struct(['b' => 'x', 'a' => 7], ['a' => new DuckDB\Integer(null), 'b' => DuckDB\Varchar::class]), "{'a':7,'b':'x'}"],
    ['Map', new DuckDB\Map([['key' => '01', 'value' => 1], ['key' => '1', 'value' => 2]], DuckDB\Varchar::class, 'INTEGER'), "map(['01','1'],[1,2])"],
    ['Union', new DuckDB\Union(null, 'b', ['a' => DuckDB\Integer::class, 'b' => 'VARCHAR']), 'union_value(b := NULL::VARCHAR)'],
    ['Geometry', new DuckDB\Geometry('POINT (1 2)'), "'POINT (1 2)'"],
]);
$conn->query("CREATE TYPE \"native'value\" AS ENUM('blue', 'red')");
$cases[] = ['CatalogValue', new DuckDB\CatalogValue('blue', "native'value", 'main'), "'blue'"];
foreach ($cases as $index => [$name, $value, $literal]) {
    $reflection = new ReflectionClass($value);
    if (!$reflection->isInternal() || !$reflection->isFinal() || !($value instanceof DuckDB\Value)) {
        throw new RuntimeException("Native final Value subclass expected: $name");
    }
    $type = $value->getType();
    $predicate = "typeof(v) = typeof(CAST(NULL AS $type)) AND v IS NOT DISTINCT FROM CAST($literal AS $type) AS ok";
    $sql = "SELECT $predicate FROM (SELECT ? AS v)";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(1, $value);
    $checks = [$stmt->execute()->fetchRow()['ok'], $conn->execute($sql, [$value])->fetchRow()['ok']];
    $table = "native_classes_$index";
    $conn->query("CREATE TABLE $table(v $type)");
    $app = $conn->appender($table);
    $app->appendRow([$value]);
    $app->beginRow();
    $app->append($value);
    $app->endRow();
    $app->close();
    foreach ($conn->query("SELECT $predicate FROM $table")->fetchAll() as $row) { $checks[] = $row['ok']; }
    if ($checks !== [true, true, true, true]) {
        throw new RuntimeException("Native class ingestion mismatch: $name");
    }
    echo $name, ": ok\n";
}
echo 'default decimal: ', (new DuckDB\Decimal('1'))->getType(), "\n";
$nested = new DuckDB\ListValue([[]], new DuckDB\ListValue(null, DuckDB\Integer::class));
echo 'nested type instance: ', json_encode($conn->execute('SELECT ? AS v', [$nested])->fetchRow()['v']), "\n";
$nestedSql = new DuckDB\Map([['key' => [1, 2], 'value' => ['x' => 'v']]], 'INTEGER[]', 'STRUCT(x VARCHAR)');
echo 'nested SQL type specs: ', $conn->execute(
    "SELECT ? IS NOT DISTINCT FROM map([[1,2]], [{'x':'v'}]) AS ok", [$nestedSql]
)->fetchRow()['ok'] ? 'yes' : 'no', "\n";

$wholeNull = new DuckDB\Union(null, null, ['a' => DuckDB\Integer::class, 'b' => DuckDB\Varchar::class]);
echo 'union whole null: ', $conn->execute('SELECT ? IS NULL AS n', [$wholeNull])->fetchRow()['n'] ? 'yes' : 'no', "\n";
$crs = new DuckDB\Geometry('POINT (1 2)', 'OGC:CRS84');
echo 'geometry crs declaration: ', $crs->getType(), "\n";
echo 'geometry crs native: ', $conn->execute('SELECT typeof(?) AS t', [$crs])->fetchRow()['t'], "\n";
foreach ([fn() => new DuckDB\Decimal('1', 0, 0), fn() => new DuckDB\Decimal('1', 39, 0),
          fn() => new DuckDB\Decimal('1', 4, 5), fn() => new DuckDB\ListValue([], 'INTEGER; SELECT 1'),
          fn() => new DuckDB\ArrayValue([], 'INTEGER', 0), fn() => new DuckDB\Union(1, null, ['a' => 'INTEGER']),
          fn() => new DuckDB\Struct(['a' => 1, 'b' => 2], ['a' => 'INTEGER, b INTEGER']),
          fn() => new DuckDB\Map([], 'INTEGER, VARCHAR', 'INTEGER'),
          fn() => new DuckDB\ListValue([], 'STRUCT(a INTEGER), b INTEGER')] as $makeInvalid) {
    try { $makeInvalid(); echo "BAD constructor accepted\n"; }
    catch (ValueError) { echo "constructor rejects invalid declaration\n"; }
}
$value = new DuckDB\Integer(1);
try { $value->__construct(2); echo "BAD mutable constructor\n"; }
catch (Error) { echo "native constructor immutable\n"; }
try { serialize($value); echo "BAD native serialization\n"; }
catch (Throwable) { echo "native serialization denied\n"; }
echo 'getType final: ', (new ReflectionMethod(DuckDB\Value::class, 'getType'))->isFinal() ? 'yes' : 'no', "\n";
class CustomMoney extends DuckDB\Value {
    public function __construct(string $amount) { parent::__construct('DECIMAL(18,2)', $amount); }
}
$money = new CustomMoney('12.345');
echo 'custom subclass: ', $conn->execute('SELECT ?::VARCHAR AS v', [$money])->fetchRow()['v'], "\n";
try { serialize($money); echo "BAD subclass serialization\n"; }
catch (Throwable) { echo "subclass serialization denied\n"; }
$uninitialized = (new ReflectionClass(CustomMoney::class))->newInstanceWithoutConstructor();
try { new DuckDB\Value('VARCHAR', $uninitialized); echo "BAD reflected input accepted\n"; }
catch (TypeError) { echo "uninitialized subclass snapshot rejected\n"; }
try { new DuckDB\ListValue([], $uninitialized); echo "BAD reflected type spec accepted\n"; }
catch (Error) { echo "uninitialized subclass type spec rejected\n"; }
$uninitialized->__construct('1.2');
echo 'reflected subclass initialized: ', $conn->execute('SELECT ?::VARCHAR AS v', [$uninitialized])->fetchRow()['v'], "\n";

?>
--EXPECT--
Boolean: ok
TinyInt: ok
SmallInt: ok
Integer: ok
BigInt: ok
UTinyInt: ok
USmallInt: ok
UInteger: ok
UBigInt: ok
HugeInt: ok
UHugeInt: ok
BigNum: ok
Float32: ok
Double: ok
Varchar: ok
Blob: ok
Bit: ok
Uuid: ok
Json: ok
Date: ok
Time: ok
TimeNs: ok
TimeTz: ok
TimestampS: ok
TimestampMs: ok
Timestamp: ok
TimestampNs: ok
TimestampTz: ok
IntervalValue: ok
Variant: ok
Decimal: ok
Enum: ok
ListValue: ok
ArrayValue: ok
Struct: ok
Map: ok
Union: ok
Geometry: ok
CatalogValue: ok
default decimal: DECIMAL(18, 3)
nested type instance: [[]]
nested SQL type specs: yes
union whole null: yes
geometry crs declaration: GEOMETRY('OGC:CRS84')
geometry crs native: GEOMETRY('OGC:CRS84')
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
constructor rejects invalid declaration
native constructor immutable
native serialization denied
getType final: yes
custom subclass: 12.35
subclass serialization denied
uninitialized subclass snapshot rejected
uninitialized subclass type spec rejected
reflected subclass initialized: 1.20
