--TEST--
BIGNUM decoding: exact decimal strings in buffered, streaming, prepared, async and nested vectors
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{BigNum, Database};

$conn = (new Database())->connect();
$cases = [
    '0', '-0', '1', '-1', '255', '-255', '256', '-256',
    '999999999', '1000000000', '4294967295', '4294967296', '-4294967296',
    '9223372036854775807', '-9223372036854775808',
    '170141183460469231731687303715884105728',
    '-170141183460469231731687303715884105729',
    '340282366920938463463374607431768211456',
    str_repeat('9', 1000), '-' . str_repeat('9', 1000),
    str_repeat('1234567890', 100), '-' . str_repeat('1234567890', 100),
    '00000000123', '-000000123', '123.75', '-123.75', null,
];
$sql = <<<'SQL'
SELECT v AS scalar,
       [v, NULL::BIGNUM, v] AS list,
       [v, v]::BIGNUM[2] AS fixed,
       struct_pack(n := v, nil := NULL::BIGNUM) AS record,
       map(['number'], [v]) AS map_value,
       union_value(big := v) AS selected_union,
       [struct_pack(numbers := [v, NULL::BIGNUM])] AS nested,
       CASE WHEN v IS NULL THEN NULL ELSE map([v], ['label']) END AS map_key,
       v::VARCHAR AS oracle,
       typeof(v) AS native_type,
       union_tag(union_value(big := v))::VARCHAR AS union_tag
FROM (SELECT ?::BIGNUM AS v)
SQL;
$stmt = $conn->prepare($sql);
function checkBignumRow(array $row, ?string $canonical): void {
    $expected = [
        'scalar' => $canonical,
        'list' => [$canonical, null, $canonical],
        'fixed' => [$canonical, $canonical],
        'record' => ['n' => $canonical, 'nil' => null],
        'map_value' => ['number' => $canonical],
        'selected_union' => $canonical,
        'nested' => [['numbers' => [$canonical, null]]],
        // BIGNUM keys use pair representation, preserving their string type.
        'map_key' => $canonical === null ? null : [['key' => $canonical, 'value' => 'label']],
        'oracle' => $canonical,
        'native_type' => 'BIGNUM',
        'union_tag' => 'big',
    ];
    if ($row !== $expected) {
        throw new RuntimeException('BIGNUM decoding differed from exact SQL VARCHAR oracle: ' . json_encode($row));
    }
}
foreach (['buffered', 'prepared', 'streaming', 'async'] as $mode) {
    foreach ($cases as $input) {
        // The SQL VARCHAR cast is the independent engine oracle. It bypasses
        // the native BIGNUM result decoder and establishes cast normalization.
        $canonical = $conn->execute('SELECT (?::BIGNUM)::VARCHAR AS v', [$input])->fetchRow()['v'];
        $typed = new BigNum($input);
        $result = match ($mode) {
            'buffered' => $conn->query(str_replace('?', $input === null ? 'NULL' : "'$input'", $sql)),
            'prepared' => $stmt->execute([$typed]),
            'streaming' => $stmt->executeStreaming([$typed]),
            'async' => $stmt->executeAsync([$typed])->await(),
        };
        checkBignumRow($result->fetchRow(), $canonical);
        if ($result->fetchRow() !== null) { throw new RuntimeException('Unexpected extra row'); }
    }
    echo $mode, ': ', count($cases), " exact scalar/composite cases\n";
}

// Streaming crosses chunk boundaries. Nested child offsets must address the
// current chunk, including inline zeros and heap-backed 1000-digit magnitudes.
$large = str_repeat('7', 1000);
$stream = $conn->queryStreaming("SELECT CASE WHEN i % 3 = 0 THEN '0'::BIGNUM
    WHEN i % 3 = 1 THEN '-$large'::BIGNUM ELSE '$large'::BIGNUM END AS v,
    [struct_pack(n := CASE WHEN i % 3 = 0 THEN '0'::BIGNUM
    WHEN i % 3 = 1 THEN '-$large'::BIGNUM ELSE '$large'::BIGNUM END)] AS nested
    FROM range(5000) t(i)");
$seen = 0;
while (($row = $stream->fetchRow()) !== null) {
    $expected = match ($seen % 3) { 0 => '0', 1 => '-' . $large, 2 => $large };
    if ($row !== ['v' => $expected, 'nested' => [['n' => $expected]]]) {
        throw new RuntimeException('BIGNUM stream chunk or nested child offset mismatch');
    }
    ++$seen;
}
if ($seen !== 5000) { throw new RuntimeException('BIGNUM stream truncated'); }
echo "streaming chunk boundaries: 5000 exact rows\n";
?>
--EXPECT--
buffered: 27 exact scalar/composite cases
prepared: 27 exact scalar/composite cases
streaming: 27 exact scalar/composite cases
async: 27 exact scalar/composite cases
streaming chunk boundaries: 5000 exact rows
