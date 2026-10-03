--TEST--
Nested result matrix: supported scalars, NULLs, mixed collections, chunk offsets and all execution/read modes
--SKIPIF--
<?php require_once __DIR__ . "/skipif.inc"; ?>
--FILE--
<?php

use DuckDB\{Database, FetchMode, Interval, Result};

$conn = (new Database())->connect();

function normalizeNested(mixed $value): mixed {
    if ($value instanceof DateTimeImmutable) {
        return ['date' => $value->format('Y-m-d H:i:s.u e')];
    }
    if ($value instanceof Interval) {
        return ['interval' => [$value->getMonths(), $value->getDays(), $value->getMicros()]];
    }
    if (is_array($value)) {
        return array_map(normalizeNested(...), $value);
    }

    return $value;
}

function equalNested(mixed $actual, mixed $expected, string $label): void {
    if (normalizeNested($actual) !== normalizeNested($expected)) {
        throw new RuntimeException($label . ': ' . json_encode(normalizeNested($actual)));
    }
}

$cases = [
    ["BOOLEAN", "true", true],
    ["TINYINT", "-128", -128],
    ["SMALLINT", "-32768", -32768],
    ["INTEGER", "-2147483648", -2147483648],
    ["BIGINT", "'-9223372036854775808'", PHP_INT_MIN],
    ["UTINYINT", "255", 255],
    ["USMALLINT", "65535", 65535],
    ["UINTEGER", "4294967295", 4294967295],
    ["UBIGINT", "'18446744073709551615'", '18446744073709551615'],
    ["HUGEINT", "'-170141183460469231731687303715884105728'", '-170141183460469231731687303715884105728'],
    ["UHUGEINT", "'340282366920938463463374607431768211455'", '340282366920938463463374607431768211455'],
    ["FLOAT", "1.5", 1.5],
    ["DOUBLE", "-1.25", -1.25],
    ["DECIMAL(4,2)", "12.34", '12.34'],
    ["DECIMAL(9,2)", "1234.56", '1234.56'],
    ["DECIMAL(18,2)", "123456.78", '123456.78'],
    ["DECIMAL(38,3)", "'-999999999999999999.999'", '-999999999999999999.999'],
    ["VARCHAR", "'short'", 'short'],
    ["VARCHAR", "'long heap-backed UTF-8: café 🦆 long text'", 'long heap-backed UTF-8: café 🦆 long text'],
    ["BLOB", "from_hex('00FF610062')", "\0\xffa\0b"],
    ["BIT", "'00101'", '00101'],
    ["UUID", "'550e8400-e29b-41d4-a716-446655440000'", '550e8400-e29b-41d4-a716-446655440000'],
    ["JSON", "'{\"a\":[1,null]}'", '{"a":[1,null]}'],
    ["ENUM('red', 'blue')", "'blue'", 'blue'],
    ["DATE", "'2024-02-29'", new DateTimeImmutable('2024-02-29 00:00:00 UTC')],
    ["DATE", "'infinity'", 'infinity'],
    ["TIME", "'12:34:56.123456'", '12:34:56.123456'],
    ["TIME_NS", "'12:34:56.123456789'", '12:34:56.123456789'],
    ["TIMETZ", "'13:14:15+02'", '13:14:15+02:00'],
    ["TIMESTAMP_S", "'2024-01-01 12:00:00'", new DateTimeImmutable('2024-01-01 12:00:00 UTC')],
    ["TIMESTAMP_MS", "'2024-01-01 12:00:00.123'", new DateTimeImmutable('2024-01-01 12:00:00.123000 UTC')],
    ["TIMESTAMP", "'2024-01-01 12:00:00.123456'", new DateTimeImmutable('2024-01-01 12:00:00.123456 UTC')],
    ["TIMESTAMP_NS", "'2024-01-01 12:00:00.123456789'", new DateTimeImmutable('2024-01-01 12:00:00.123456 UTC')],
    ["TIMESTAMPTZ", "'2024-01-01 12:00:00+02'", new DateTimeImmutable('2024-01-01 10:00:00 UTC')],
    ["TIMESTAMP", "'-infinity'", '-infinity'],
    ["INTERVAL", "'1 year 2 months 3 days 04:05:06.789'", new DuckDB\Interval(14, 3, 14706789000)],
    ["GEOMETRY", "'POINT (1 2)'", hex2bin('0101000000000000000000f03f0000000000000040')],
];
foreach (['buffered', 'prepared', 'streaming', 'async'] as $mode) {
    foreach ($cases as [$type, $expression, $value]) {
        $sql = "SELECT [v,NULL::$type] AS xs, [v,NULL]::$type" . "[2] AS fixed,
            struct_pack(value := v, empty := []::$type" . "[], nil := NULL::$type) AS record,
            map(['value','empty'], [[v,NULL]::$type" . "[],[]::$type" . "[]]) AS mapping,
            CAST(union_value(selected := v) AS UNION(selected $type, text VARCHAR)) AS tagged
            FROM (SELECT CAST($expression AS $type) AS v UNION ALL SELECT NULL::$type) q
            WHERE ?::BOOLEAN";
        $statement = $conn->prepare($sql);
        $metadata = [];
        for ($column = 0; $column < 5; $column++) {
            $metadata[] = $statement->columnType($column);
        }
        $result = match ($mode) {
            'buffered' => $conn->query(str_replace('?::BOOLEAN', 'true', $sql)),
            'prepared' => $statement->execute([true]),
            'streaming' => $statement->executeStreaming([true]),
            'async' => $statement->executeAsync([true])->await(),
        };
        for ($column = 0; $column < 5; $column++) {
            if ($result->columnType($column) !== $metadata[$column]) {
                throw new RuntimeException("$type nested metadata changed in $mode");
            }
        }
        $expected = static fn($v) => [
            'xs' => [$v, null], 'fixed' => [$v, null],
            'record' => ['value' => $v, 'empty' => [], 'nil' => null],
            'mapping' => ['value' => [$v, null], 'empty' => []], 'tagged' => $v,
        ];
        equalNested($result->fetchRow(), $expected($value), "$type/$mode first row");
        // fetchAll must resume after fetchRow rather than replay the first row.
        equalNested($result->fetchAll(), [$expected(null)], "$type/$mode NULL row");
        if ($result->fetchRow() !== null) {
            throw new RuntimeException('Scalar matrix cursor did not end');
        }
    }
    echo $mode, ': ', count($cases), " scalar types in all collection families\n";
}

// Variable LIST lengths, whole-value NULLs and sparse MAP keys produce different
// child offsets. Filtering/slicing a stored table also exercises dictionary
// selections; constant descendants share storage before DuckDB normalizes it.
$conn->query("CREATE TABLE nested_source AS SELECT i,
    CASE WHEN i % 11 = 0 THEN NULL ELSE struct_pack(
        id := i::INTEGER, price := 12.34::DECIMAL(18,2),
        bytes := from_hex('610062'), flags := [true,false]::BOOLEAN[],
        stamp := TIMESTAMP '2024-01-01 12:00:00.123456') END AS payload
    FROM range(5000) t(i)");
// DuckDB 1.5.x does not implement CASE over ARRAY values. An outer join to a
// materialized ARRAY column supplies whole-value NULLs without such a CASE.
$conn->query("CREATE TABLE nested_arrays AS SELECT i AS array_id,
    [payload,NULL]::STRUCT(id INTEGER, price DECIMAL(18,2), bytes BLOB, flags BOOLEAN[], stamp TIMESTAMP)[2] AS fixed_value
    FROM nested_source");
$largeSql = <<<'SQL'
SELECT i::BIGINT AS id,
       CASE WHEN i%17=0 THEN NULL::STRUCT(id INTEGER, price DECIMAL(18,2), bytes BLOB, flags BOOLEAN[], stamp TIMESTAMP)[]
            WHEN i%3=0 THEN []::STRUCT(id INTEGER, price DECIMAL(18,2), bytes BLOB, flags BOOLEAN[], stamp TIMESTAMP)[]
            WHEN i%3=1 THEN [payload] ELSE [NULL,payload] END AS xs,
       fixed_value AS fixed,
       CASE WHEN i%23=0 THEN NULL ELSE struct_pack(payload := payload, empty := []::INTEGER[], nil := NULL::INTEGER) END AS record,
       CASE WHEN i%29=0 THEN NULL WHEN i%5=0 THEN map([]::VARCHAR[],[]::INTEGER[]) ELSE map(['01','1'],[i::INTEGER,NULL]) END AS numeric_keys,
       CASE WHEN i%6=0 THEN NULL ELSE map([[i::INTEGER,i::INTEGER+1],[]::INTEGER[]],[[i::INTEGER,NULL],[]::INTEGER[]]) END AS composite_keys,
       CASE WHEN i%13=0 THEN NULL::UNION(rec STRUCT(id INTEGER, price DECIMAL(18,2), bytes BLOB, flags BOOLEAN[], stamp TIMESTAMP), id BIGINT, text VARCHAR)
            WHEN i%4=0 THEN union_value(rec := payload)
            WHEN i%4=1 THEN union_value(id := i::BIGINT)
            WHEN i%4=2 THEN union_value(text := 'row'||i::VARCHAR)
            ELSE union_value(text := NULL::VARCHAR) END AS tagged,
       union_tag(tagged)::VARCHAR AS native_tag,
       struct_pack(children := [struct_pack(value := payload, ids := [i::INTEGER,NULL])]) AS deep
FROM nested_source LEFT JOIN nested_arrays ON i=array_id AND i%19<>0
WHERE i >= ? AND i%7<>0 ORDER BY i
SQL;
$largeStatement = $conn->prepare($largeSql);

function expectedLargeNested(int $i): array {
    $payload = $i % 11 === 0 ? null : [
        'id' => $i, 'price' => '12.34', 'bytes' => "a\0b", 'flags' => [true, false],
        'stamp' => new DateTimeImmutable('2024-01-01 12:00:00.123456 UTC'),
    ];
    $wholeNull = $i % 13 === 0;
    $tag = $wholeNull ? null : match ($i % 4) {
        0 => 'rec', 1 => 'id', default => 'text'
    };
    $tagged = $wholeNull ? null : match ($i % 4) {
        0 => $payload, 1 => $i, 2 => "row$i", 3 => null,
    };

    return [
        'id' => $i,
        'xs' => $i % 17 === 0 ? null : match ($i % 3) {
            0 => [], 1 => [$payload], 2 => [null, $payload]
        },
        'fixed' => $i % 19 === 0 ? null : [$payload, null],
        'record' => $i % 23 === 0 ? null : ['payload' => $payload, 'empty' => [], 'nil' => null],
        'numeric_keys' => $i % 29 === 0 ? null : ($i % 5 === 0 ? [] : ['01' => $i, 1 => null]),
        'composite_keys' => $i % 6 === 0 ? null : [
            ['key' => [$i, $i + 1], 'value' => [$i, null]], ['key' => [], 'value' => []],
        ],
        'tagged' => $tagged, 'native_tag' => $tag,
        'deep' => ['children' => [['value' => $payload, 'ids' => [$i, null]]]],
    ];
}

$ids = array_values(array_filter(range(0, 4999), static fn(int $i): bool => $i % 7 !== 0));
foreach (['buffered', 'prepared', 'streaming', 'async'] as $mode) {
    foreach (['fetchRow', 'fetchAll', 'iterator'] as $reader) {
        $result = match ($mode) {
            'buffered' => $conn->query(str_replace('?', '0', $largeSql)),
            'prepared' => $largeStatement->execute([0]),
            'streaming' => $largeStatement->executeStreaming([0]),
            'async' => $largeStatement->executeAsync([0])->await(),
        };
        for ($column = 0; $column < $result->columnCount(); ++$column) {
            if ($result->columnType($column) !== $largeStatement->columnType($column)) {
                throw new RuntimeException('Mixed composite metadata mismatch');
            }
        }
        $index = 0;
        if ($reader === 'iterator') {
            // Iteration owns the forward-only cursor from its first row.
            foreach ($result as $key => $row) {
                if ($key !== $index) {
                    throw new RuntimeException('Iterator cursor key mismatch');
                }
                equalNested($row, expectedLargeNested($ids[$index++]), "$mode/$reader row");
            }
        } else {
            // Begin inside the first chunk, then fetch the remainder. fetchAll
            // must resume the shared cursor without replaying these rows.
            for (; $index < 3; ++$index) {
                equalNested($result->fetchRow(), expectedLargeNested($ids[$index]), "$mode/$reader prefix");
            }
            if ($reader === 'fetchRow') {
                while (($row = $result->fetchRow()) !== null) {
                    equalNested($row, expectedLargeNested($ids[$index++]), "$mode/$reader row");
                }
            } else {
                $remaining = $result->fetchAll();
                foreach ($remaining as $row) {
                    equalNested($row, expectedLargeNested($ids[$index++]), "$mode/$reader row");
                }
                unset($remaining);
            }
        }
        if ($index !== count($ids) || $result->fetchRow() !== null) {
            throw new RuntimeException('Mixed composite cursor duplicated or omitted rows');
        }
        echo "$mode/$reader: $index filtered mixed rows\n";
    }
}

// Abandoning a decoded stream must release nested chunk state, allowing a new
// execution on the connection. Buffered results remain independently readable.
$stream = $largeStatement->executeStreaming([0]);
equalNested($stream->fetchRow(), expectedLargeNested(1), 'abandoned stream first row');
unset($stream);
if ($conn->query('SELECT 42 AS n')->fetchRow()['n'] !== 42) {
    throw new RuntimeException('Abandoned nested stream left connection unusable');
}
echo "abandoned nested stream cleanup: ok\n";
?>
--EXPECT--
buffered: 37 scalar types in all collection families
prepared: 37 scalar types in all collection families
streaming: 37 scalar types in all collection families
async: 37 scalar types in all collection families
buffered/fetchRow: 4285 filtered mixed rows
buffered/fetchAll: 4285 filtered mixed rows
buffered/iterator: 4285 filtered mixed rows
prepared/fetchRow: 4285 filtered mixed rows
prepared/fetchAll: 4285 filtered mixed rows
prepared/iterator: 4285 filtered mixed rows
streaming/fetchRow: 4285 filtered mixed rows
streaming/fetchAll: 4285 filtered mixed rows
streaming/iterator: 4285 filtered mixed rows
async/fetchRow: 4285 filtered mixed rows
async/fetchAll: 4285 filtered mixed rows
async/iterator: 4285 filtered mixed rows
abandoned nested stream cleanup: ok
