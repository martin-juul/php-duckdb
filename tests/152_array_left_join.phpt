--TEST--
LEFT JOIN returns fixed-size ARRAY values and NULLs for unmatched rows
--SKIPIF--
<?php require_once __DIR__ . "/skipif.inc"; ?>
--FILE--
<?php

use DuckDB\Database;

// Unpatched DuckDB 2.0.0 casts the wrong rows when a unique-key hash join
// gathers ARRAY columns for matched rows only. It fails these queries with
// "Cannot cast list with length 0 to array" or garbage lengths; the
// array-join-gather engine patch fixes it.
$conn = (new Database())->connect();

function normalizeFixed(mixed $value): mixed {
    if ($value instanceof DateTimeImmutable) {
        return $value->format('Y-m-d H:i:s.u e');
    }
    if (is_array($value)) {
        return array_map(normalizeFixed(...), $value);
    }

    return $value;
}

function checkRows(iterable $rows, array $ids, callable $expected, string $label): void {
    $index = 0;
    foreach ($rows as $row) {
        $id = $ids[$index] ?? null;
        if ($id === null || normalizeFixed($row) !== normalizeFixed($expected($id))) {
            throw new RuntimeException("$label row $index: " . json_encode(normalizeFixed($row)));
        }
        ++$index;
    }
    if ($index !== count($ids)) {
        throw new RuntimeException("$label returned $index rows instead of " . count($ids));
    }
    echo "$label: $index rows\n";
}

function streamRows(DuckDB\Result $result): Generator {
    while (($row = $result->fetchRow()) !== null) {
        yield $row;
    }
}

// The reproducer: every other probe row has no match.
$conn->query("CREATE TABLE a AS SELECT i AS id, [i, i+1]::INTEGER[2] AS arr FROM range(10) t(i)");
$smallSql = "SELECT i::BIGINT AS i, arr FROM range(10) t(i)
    LEFT JOIN (SELECT * FROM a WHERE id%2=0) ON i=id ORDER BY i";
$smallExpected = static fn(int $i): array => ['i' => $i, 'arr' => $i % 2 === 0 ? [$i, $i + 1] : null];
$smallIds = range(0, 9);

checkRows($conn->query($smallSql)->fetchAll(), $smallIds, $smallExpected, 'small buffered');
checkRows(streamRows($conn->prepare($smallSql)->executeStreaming()), $smallIds, $smallExpected, 'small streaming');

// The nested result matrix's former shape: several chunks of STRUCT elements,
// NULL elements, and unmatched rows from the join condition.
$conn->query("CREATE TABLE nested_source AS SELECT i,
    CASE WHEN i % 11 = 0 THEN NULL ELSE struct_pack(
        id := i::INTEGER, price := 12.34::DECIMAL(18,2),
        bytes := from_hex('610062'), flags := [true,false]::BOOLEAN[],
        stamp := TIMESTAMP '2024-01-01 12:00:00.123456') END AS payload
    FROM range(5000) t(i)");
$conn->query("CREATE TABLE nested_arrays AS SELECT i AS array_id,
    [payload,NULL]::STRUCT(id INTEGER, price DECIMAL(18,2), bytes BLOB, flags BOOLEAN[], stamp TIMESTAMP)[2] AS fixed_value
    FROM nested_source");
$largeSql = "SELECT i::BIGINT AS id, fixed_value AS fixed
    FROM nested_source LEFT JOIN nested_arrays ON i=array_id AND i%19<>0
    WHERE i >= ? AND i%7<>0 ORDER BY i";
$largeExpected = static function (int $i): array {
    $payload = $i % 11 === 0 ? null : [
        'id' => $i, 'price' => '12.34', 'bytes' => "a\0b", 'flags' => [true, false],
        'stamp' => new DateTimeImmutable('2024-01-01 12:00:00.123456 UTC'),
    ];

    return ['id' => $i, 'fixed' => $i % 19 === 0 ? null : [$payload, null]];
};
$largeIds = array_values(array_filter(range(0, 4999), static fn(int $i): bool => $i % 7 !== 0));

checkRows($conn->query(str_replace('?', '0', $largeSql))->fetchAll(), $largeIds, $largeExpected, 'nested buffered');
checkRows(streamRows($conn->prepare($largeSql)->executeStreaming([0])), $largeIds, $largeExpected, 'nested streaming');
?>
--EXPECT--
small buffered: 10 rows
small streaming: 10 rows
nested buffered: 4285 rows
nested streaming: 4285 rows
