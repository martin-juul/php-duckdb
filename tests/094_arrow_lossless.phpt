--TEST--
Arrow defaults preserve unsigned extremes, bit strings, timezones, geometry CRS and transactions
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

function rejectsLoss(callable $operation): void
{
    try {
        $operation();
    } catch (DuckDB\Exception $error) {
        check(str_contains($error->getMessage(), 'Lossless Arrow conversion'), 'Unsafe export error must explain lossless conversion');
        check(str_contains($error->getMessage(), 'arrow_lossless_conversion=true'), 'Unsafe export error must explain configuration');
        return;
    }

    throw new RuntimeException('Unsafe Arrow export did not throw');
}

function geometryCrs(array $field): void
{
    $metadata = array_column($field['metadata'], 'value', 'key');
    check(($metadata['ARROW:extension:name'] ?? null) === 'geoarrow.wkb', 'Geometry extension is missing');
    $description = json_decode($metadata['ARROW:extension:metadata'] ?? '', true, 512, JSON_THROW_ON_ERROR);
    check(($description['crs']['id']['authority'] ?? null) === 'OGC', 'Geometry CRS authority is missing');
    check(($description['crs']['id']['code'] ?? null) === 'CRS84', 'Geometry CRS code changed');
}

$db = new DuckDB\Database();
$source = $db->connect();
$destination = $db->connect();
$observer = $db->connect();
$sql = "SELECT '340282366920938463463374607431768211455'::UHUGEINT AS maximum,
    '-170141183460469231731687303715884105728'::HUGEINT AS minimum_signed,
    '170141183460469231731687303715884105727'::HUGEINT AS maximum_signed,
    '00101'::BIT AS bits, '12:34:56.123456+02'::TIMETZ AS clock";
$expected = $source->query($sql)->fetchAll();
$native = $destination->dataChunkFromArrow($source->query($sql)->fetchArrowChunk());
check($native->toRows() === $expected, 'Default export changed unsigned, bit or timezone values');
check(array_column($native->columns(), 'type') === ['UHUGEINT', 'HUGEINT', 'HUGEINT', 'BIT', 'TIME_TZ'], 'Default import lost native types');
$again = $native->toArrow($destination);
check($source->dataChunkFromArrow($again)->toRows() === $expected, 'Native re-export changed lossless values');
$destination->query('CREATE TABLE exact_values(maximum UHUGEINT, minimum_signed HUGEINT, maximum_signed HUGEINT, bits BIT, clock TIMETZ)');
$appender = $destination->appender('exact_values');
$appender->appendChunk($native);
$appender->appendArrow($native->toArrow($destination));
$appender->close();
check($destination->query('SELECT * FROM exact_values')->fetchAll() === [$expected[0], $expected[0]], 'Lossless native append changed values');
echo "default exports preserve extreme unsigned values, BIT and TIMETZ through appending\n";

// Explicit settings are honored, but unsafe scalar and nested schemas fail before advancing.
$source->query('SET arrow_lossless_conversion = false');
$changedSetting = $source->query($sql);
rejectsLoss(fn() => $changedSetting->arrowSchema());
rejectsLoss(fn() => $changedSetting->fetchArrowChunk());
check($changedSetting->fetchRow() === $expected[0], 'Runtime setting rejection advanced its unread row');
$source->query('SET arrow_lossless_conversion = true');
$lossyDb = new DuckDB\Database(':memory:', ['arrow_lossless_conversion' => false]);
$lossy = $lossyDb->connect();
check($lossy->query("SELECT current_setting('arrow_lossless_conversion') AS enabled")->fetchRow() === ['enabled' => false], 'Explicit database configuration was overridden');
$unsafe = [
    "'-170141183460469231731687303715884105728'::HUGEINT",
    "'170141183460469231731687303715884105727'::HUGEINT",
    "'340282366920938463463374607431768211455'::UHUGEINT",
    "'00101'::BIT",
    "'12:34:56+02'::TIMETZ",
    "[NULL, '340282366920938463463374607431768211455'::UHUGEINT]",
    "{'bits': '00101'::BIT}",
    "['00101'::BIT, NULL]::BIT[2]",
    "union_value(clock := '12:34:56+02'::TIMETZ)",
    "map(['clock'], ['12:34:56+02'::TIMETZ])",
];
foreach ($unsafe as $expression) {
    $unsafeSql = 'SELECT ' . $expression . ' AS v';
    $expectedRow = $lossy->query($unsafeSql)->fetchRow();
    $result = $lossy->query($unsafeSql);
    rejectsLoss(fn() => $result->arrowSchema());
    rejectsLoss(fn() => $result->fetchArrowChunk());
    check($result->fetchRow() === $expectedRow, 'Unsafe export advanced its unread row');
}
rejectsLoss(fn() => $native->arrowSchema($lossy));
rejectsLoss(fn() => $native->toArrow($lossy));
check($native->toRows() === $expected, 'Unsafe native export changed its source chunk');
$lossy->query('SET arrow_lossless_conversion = true');
check($lossy->dataChunkFromArrow($native->toArrow($lossy))->toRows() === $expected, 'Native chunk cannot retry after unsafe export rejection');
echo "explicit lossy settings reject unsafe scalar and nested exports without consuming rows\n";

// No caller transaction is needed to resolve CRS metadata on either connection.
$sql = "SELECT 'POINT (1 2)'::GEOMETRY('OGC:CRS84') AS point,
    {'point': 'POINT (3 4)'::GEOMETRY('OGC:CRS84')} AS nested,
    ['POINT (5 6)'::GEOMETRY('OGC:CRS84'), NULL] AS points";
$expectedGeometry = $source->query($sql)->fetchAll();
$result = $source->query($sql);
$schema = $result->arrowSchema()->toArray();
geometryCrs($schema['children'][0]);
geometryCrs($schema['children'][1]['children'][0]);
geometryCrs($schema['children'][2]['children'][0]);
$geometry = $destination->dataChunkFromArrow($result->fetchArrowChunk());
check($geometry->toRows() === $expectedGeometry, 'Geometry values changed');
$reexported = $geometry->arrowSchema($destination)->toArray();
geometryCrs($reexported['children'][0]);
geometryCrs($reexported['children'][1]['children'][0]);
geometryCrs($reexported['children'][2]['children'][0]);
check($source->dataChunkFromArrow($geometry->toArrow($destination))->toRows() === $expectedGeometry, 'Geometry re-export changed values');
$destination->query("CREATE TABLE geometry_copy(point GEOMETRY('OGC:CRS84'), nested STRUCT(point GEOMETRY('OGC:CRS84')), points GEOMETRY('OGC:CRS84')[])");
$appender = $destination->appender('geometry_copy');
$appender->appendChunk($geometry);
$appender->appendArrow($geometry->toArrow($destination));
$appender->close();
check($destination->query('SELECT * FROM geometry_copy')->fetchAll() === [$expectedGeometry[0], $expectedGeometry[0]], 'Geometry CRS table append changed values');
echo "geometry CRS survives schema, nested import, re-export and append without explicit transactions\n";

$source->query('CREATE TABLE markers(id INTEGER)');
$destination->query("CREATE TABLE transactional_geometry(point GEOMETRY('OGC:CRS84'))");
$source->beginTransaction();
$destination->beginTransaction();
$source->query('INSERT INTO markers VALUES (1)');
$transactionSql = "SELECT 'POINT (7 8)'::GEOMETRY('OGC:CRS84') AS point";
$transactionResult = $source->query($transactionSql);
geometryCrs($transactionResult->arrowSchema()->toArray()['children'][0]);
$transactionChunk = $destination->dataChunkFromArrow($transactionResult->fetchArrowChunk());
geometryCrs($transactionChunk->arrowSchema($destination)->toArray()['children'][0]);
$roundtrip = $source->dataChunkFromArrow($transactionChunk->toArrow($destination));
check($roundtrip->toRows() === $transactionChunk->toRows(), 'Caller transaction conversion changed geometry');
$appender = $destination->appender('transactional_geometry');
$appender->appendChunk($transactionChunk);
$appender->close();
check($observer->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 0], 'Arrow export committed source transaction');
check($observer->query('SELECT count(*) AS n FROM transactional_geometry')->fetchRow() === ['n' => 0], 'Arrow import committed destination transaction');
$unsupported = $source->query("SELECT 'text'::VARIANT AS v");
try {
    $unsupported->fetchArrowChunk();
    throw new RuntimeException('VARIANT export did not reject unsupported type');
} catch (DuckDB\Exception $error) {
    check(str_contains($error->getMessage(), 'VARIANT'), 'Unsupported error does not identify VARIANT');
}
check($source->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 1], 'Conversion failure aborted caller transaction');
$source->rollback();
$destination->rollback();
check($observer->query('SELECT count(*) AS n FROM markers')->fetchRow() === ['n' => 0], 'Source rollback lost its transaction');
check($observer->query('SELECT count(*) AS n FROM transactional_geometry')->fetchRow() === ['n' => 0], 'Destination rollback lost its transaction');
echo "successful and failed conversions preserve caller transactions and rollback\n";
?>
--EXPECT--
default exports preserve extreme unsigned values, BIT and TIMETZ through appending
explicit lossy settings reject unsafe scalar and nested exports without consuming rows
geometry CRS survives schema, nested import, re-export and append without explicit transactions
successful and failed conversions preserve caller transactions and rollback
