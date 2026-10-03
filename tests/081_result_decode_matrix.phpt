--TEST--
Result: scalar PHP decoding across execution and fetch paths
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
if (PHP_INT_SIZE !== 8) { die('skip requires 64-bit PHP integer boundaries'); }
?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query("SET TimeZone = 'UTC'");
$utc = new DateTimeZone('UTC');
$date = static fn(string $value) => new DateTimeImmutable($value, $utc);
$cases = [
    'boolean_true' => ['TRUE', true],
    'boolean_false' => ['FALSE', false],
    'tiny_min' => ['(-128)::TINYINT', -128],
    'tiny_max' => ['127::TINYINT', 127],
    'small_min' => ['(-32768)::SMALLINT', -32768],
    'small_max' => ['32767::SMALLINT', 32767],
    'integer_min' => ['(-2147483648)::INTEGER', -2147483648],
    'integer_max' => ['2147483647::INTEGER', 2147483647],
    'bigint_min' => ["'-9223372036854775808'::BIGINT", PHP_INT_MIN],
    'bigint_max' => ["'9223372036854775807'::BIGINT", PHP_INT_MAX],
    'utinyint_max' => ['255::UTINYINT', 255],
    'usmallint_max' => ['65535::USMALLINT', 65535],
    'uinteger_max' => ['4294967295::UINTEGER', 4294967295],
    'ubigint_fits' => ["'9223372036854775807'::UBIGINT", PHP_INT_MAX],
    'ubigint_max' => ["'18446744073709551615'::UBIGINT", '18446744073709551615'],
    'hugeint_fits' => ['42::HUGEINT', 42],
    'hugeint_min' => ["'-170141183460469231731687303715884105728'::HUGEINT", '-170141183460469231731687303715884105728'],
    'hugeint_max' => ["'170141183460469231731687303715884105727'::HUGEINT", '170141183460469231731687303715884105727'],
    'uhugeint_fits' => ['42::UHUGEINT', 42],
    'uhugeint_max' => ["'340282366920938463463374607431768211455'::UHUGEINT", '340282366920938463463374607431768211455'],
    'float' => ['1.5::FLOAT', 1.5],
    'double' => ['(-2.25)::DOUBLE', -2.25],
    'float_nan' => ["'NaN'::FLOAT", NAN],
    'double_nan' => ["'NaN'::DOUBLE", NAN],
    'float_positive_infinity' => ["'infinity'::FLOAT", INF],
    'double_negative_infinity' => ["'-infinity'::DOUBLE", -INF],
    'decimal_int16' => ["'12.30'::DECIMAL(4,2)", '12.30'],
    'decimal_int32' => ["'-1234567.80'::DECIMAL(9,2)", '-1234567.80'],
    'decimal_int64' => ["'1234567890123456.70'::DECIMAL(18,2)", '1234567890123456.70'],
    'decimal_int128' => ["'-123456789012345678901234567890123456.70'::DECIMAL(38,2)", '-123456789012345678901234567890123456.70'],
    'decimal_zero' => ['0::DECIMAL(18,3)', '0.000'],
    'varchar_empty' => ["''::VARCHAR", ''],
    'varchar_unicode' => ["'héllo 🦆'::VARCHAR", 'héllo 🦆'],
    'varchar_nul' => ["'a' || chr(0) || 'b'", "a\0b"],
    'blob_empty' => ["''::BLOB", ''],
    'blob_binary' => ["from_hex('00ff6100')", "\0\xffa\0"],
    'bit_padding' => ["'101011'::BIT", '101011'],
    'bit_zero' => ["'0'::BIT", '0'],
    'bit_long' => ["'000000001'::BIT", '000000001'],
    'uuid' => ["'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11'::UUID", 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'],
    'json' => ["'{\"a\": [1, null]}'::JSON", '{"a": [1, null]}'],
    'enum' => ["'blue'::ENUM('red', 'blue')", 'blue'],
    'geometry' => ["'POINT (1 2)'::GEOMETRY", hex2bin('0101000000000000000000f03f0000000000000040')],
    'date' => ["DATE '2024-02-29'", $date('2024-02-29 00:00:00')],
    'date_positive_infinity' => ["DATE 'infinity'", 'infinity'],
    'date_negative_infinity' => ["DATE '-infinity'", '-infinity'],
    'time' => ["TIME '12:34:56'", '12:34:56'],
    'time_microseconds' => ["TIME '23:59:59.123456'", '23:59:59.123456'],
    'time_nanoseconds' => ["'12:34:56.123456789'::TIME_NS", '12:34:56.123456789'],
    'timetz' => ["TIMETZ '13:14:15.123456-02:30'", '13:14:15.123456-02:30'],
    'timestamp_seconds' => ["TIMESTAMP_S '2024-01-01 12:00:00'", $date('2024-01-01 12:00:00')],
    'timestamp_milliseconds' => ["TIMESTAMP_MS '2024-01-01 12:00:00.123'", $date('2024-01-01 12:00:00.123000')],
    'timestamp_microseconds' => ["TIMESTAMP '2024-01-01 12:00:00.123456'", $date('2024-01-01 12:00:00.123456')],
    'timestamp_nanoseconds' => ["TIMESTAMP_NS '2024-01-01 12:00:00.123456789'", $date('2024-01-01 12:00:00.123456')],
    'timestamp_before_epoch' => ["TIMESTAMP '1969-12-31 23:59:59.999999'", $date('1969-12-31 23:59:59.999999')],
    'timestamptz' => ["TIMESTAMPTZ '2024-01-01 12:00:00.123456+02'", $date('2024-01-01 10:00:00.123456')],
    'timestamp_positive_infinity' => ["TIMESTAMP 'infinity'", 'infinity'],
    'timestamp_negative_infinity' => ["TIMESTAMP '-infinity'", '-infinity'],
    'interval' => ["INTERVAL '1 year 2 months 3 days 04:05:06.789'", new DuckDB\Interval(14, 3, 14706789000)],
    'interval_negative' => ["INTERVAL '-3 days'", new DuckDB\Interval(0, -3, 0)],
    'null_untyped' => ['NULL', null],
];
// Typed NULL must bypass decoding for every supported scalar family too.
foreach (['BOOLEAN', 'TINYINT', 'SMALLINT', 'INTEGER', 'BIGINT', 'UTINYINT', 'USMALLINT',
          'UINTEGER', 'UBIGINT', 'HUGEINT', 'UHUGEINT', 'FLOAT', 'DOUBLE', 'DECIMAL(18,2)',
          'VARCHAR', 'BLOB', 'BIT', 'UUID', 'JSON', "ENUM('red', 'blue')", 'GEOMETRY',
          'DATE', 'TIME', 'TIME_NS', 'TIMETZ', 'TIMESTAMP_S', 'TIMESTAMP_MS', 'TIMESTAMP',
          'TIMESTAMP_NS', 'TIMESTAMPTZ', 'INTERVAL'] as $index => $type) {
    $cases['typed_null_' . $index] = ["NULL::$type", null];
}

$columns = [];
foreach ($cases as $name => [$expression]) { $columns[] = "$expression AS $name"; }
$sql = 'SELECT ' . implode(', ', $columns) . ' WHERE ?::INTEGER = 1';
$literalSql = str_replace('?::INTEGER', '1::INTEGER', $sql);
$statement = $conn->prepare($sql);
$paths = [
    'query' => static fn() => $conn->query($literalSql),
    'streaming' => static fn() => $conn->queryStreaming($literalSql),
    'execute' => static fn() => $conn->execute($sql, [1]),
    'prepared' => static fn() => $statement->execute([1]),
    'prepared streaming' => static fn() => $statement->executeStreaming([1]),
    'prepared async' => static fn() => $statement->executeAsync([1])->await(),
];
function normalized_scalar(mixed $value): mixed {
    if ($value instanceof DateTimeImmutable) {
        return [get_class($value), $value->format('Y-m-d H:i:s.u e')];
    }
    if ($value instanceof DuckDB\Interval) {
        return [get_class($value), $value->getMonths(), $value->getDays(), $value->getMicros()];
    }
    return $value;
}
$failures = [];
foreach ($paths as $path => $run) {
    foreach (['fetchRow', 'fetchAll', 'iterator'] as $fetch) {
        $result = $run();
        $rows = match ($fetch) {
            'fetchRow' => [$result->fetchRow()],
            'fetchAll' => $result->fetchAll(),
            'iterator' => iterator_to_array($result, false),
        };
        if (count($rows) !== 1 || array_keys($rows[0]) !== array_keys($cases)) {
            throw new RuntimeException("Wrong row shape: $path/$fetch");
        }
        foreach ($cases as $name => [$expression, $expected]) {
            $actual = $rows[0][$name];
            $matches = is_float($expected) && is_nan($expected)
                ? is_float($actual) && is_nan($actual)
                : normalized_scalar($actual) === normalized_scalar($expected);
            if (!$matches) {
                $failures[] = "$path/$fetch/$name: " . var_export(normalized_scalar($actual), true);
            }
        }
        if ($result->fetchRow() !== null) { throw new RuntimeException("Unexpected extra row: $path/$fetch"); }
        unset($result);
    }
    echo $path, ': ', count($cases), " scalar cases across fetchRow/fetchAll/iterator\n";
}
if ($failures !== []) { throw new RuntimeException(implode("\n", $failures)); }
?>
--EXPECT--
query: 92 scalar cases across fetchRow/fetchAll/iterator
streaming: 92 scalar cases across fetchRow/fetchAll/iterator
execute: 92 scalar cases across fetchRow/fetchAll/iterator
prepared: 92 scalar cases across fetchRow/fetchAll/iterator
prepared streaming: 92 scalar cases across fetchRow/fetchAll/iterator
prepared async: 92 scalar cases across fetchRow/fetchAll/iterator
