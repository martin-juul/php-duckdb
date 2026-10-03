--TEST--
Typed values: every built-in family through bindValue, execute, appendRow and append
--SKIPIF--
<?php require_once __DIR__ . "/skipif.inc"; ?>
--FILE--
<?php
use DuckDB\Value;
$conn = (new DuckDB\Database())->connect();
$cases = [
    ["BOOLEAN", true, "true"],
    ["TINYINT", '-128', "-128"],
    ["SMALLINT", '-32768', "-32768"],
    ["INTEGER", '-2147483648', "-2147483648"],
    ["BIGINT", '-9223372036854775808', "'-9223372036854775808'"],
    ["UTINYINT", '255', "255"],
    ["USMALLINT", '65535', "65535"],
    ["UINTEGER", '4294967295', "4294967295"],
    ["UBIGINT", '18446744073709551615', "'18446744073709551615'"],
    ["HUGEINT", '170141183460469231731687303715884105727', "'170141183460469231731687303715884105727'"],
    ["UHUGEINT", '340282366920938463463374607431768211455', "'340282366920938463463374607431768211455'"],
    ["BIGNUM", '123456789012345678901234567890123456789012345678901234567890', "'123456789012345678901234567890123456789012345678901234567890'"],
    ["FLOAT", 1.5, "1.5"],
    ["DOUBLE", -1.25, "-1.25"],
    ["DECIMAL(18,2)", '12.345', "12.35"],
    ["VARCHAR", 'hello', "'hello'"],
    ["BLOB", "a\0b", "from_hex('610062')"],
    ["BIT", '00101', "'00101'"],
    ["UUID", '550e8400-e29b-41d4-a716-446655440000', "'550e8400-e29b-41d4-a716-446655440000'"],
    ["JSON", '{"a":[1,null]}', "'{\"a\":[1,null]}'"],
    ["DATE", '2026-10-03', "'2026-10-03'"],
    ["TIME", '12:34:56.123456', "'12:34:56.123456'"],
    ["TIME_NS", '12:34:56.123456789', "'12:34:56.123456789'"],
    ["TIMETZ", '12:34:56+02:00', "'12:34:56+02:00'"],
    ["TIMESTAMP_S", '2026-10-03 12:34:56', "'2026-10-03 12:34:56'"],
    ["TIMESTAMP_MS", '2026-10-03 12:34:56.123', "'2026-10-03 12:34:56.123'"],
    ["TIMESTAMP", '2026-10-03 12:34:56.123456', "'2026-10-03 12:34:56.123456'"],
    ["TIMESTAMP_NS", '2026-10-03 12:34:56.123456789', "'2026-10-03 12:34:56.123456789'"],
    ["TIMESTAMPTZ", '2026-10-03 12:34:56.123456+02:00', "'2026-10-03 12:34:56.123456+02:00'"],
    ["INTERVAL", new DuckDB\Interval(2, 3, 4000000), "'2 months 3 days 4 seconds'"],
    ["ENUM('red', 'blue')", 'blue', "'blue'"],
    ["INTEGER[]", [], "[]"],
    ["INTEGER[][]", [[], [null, null], [1, 2]], "[[], [NULL,NULL], [1,2]]"],
    ["INTEGER[2]", [1, null], "[1,NULL]"],
    ["STRUCT(a INTEGER, b VARCHAR)", ['b' => 'x', 'a' => 7], "{'a':7,'b':'x'}"],
    ["MAP(VARCHAR, INTEGER)", [['key' => '01', 'value' => 1], ['key' => '1', 'value' => 2]], "map(['01','1'],[1,2])"],
    ["MAP(INTEGER[], VARCHAR)", [['key' => [1,2], 'value' => 'x']], "map([[1,2]],['x'])"],
    ["UNION(a INTEGER, b VARCHAR)", ['tag' => 'b', 'value' => null], "union_value(b := NULL::VARCHAR)"],
    ["VARIANT", new Value('JSON', '{"a":1}'), "'{\"a\":1}'::JSON::VARIANT"],
    ["GEOMETRY", 'POINT (1 2)', "'POINT (1 2)'"],
 ];
foreach ($cases as $index => [$type, $input, $expected]) {
    $value = new Value($type, $input);
    $assert = "typeof(v) = typeof(CAST(NULL AS $type)) AND v IS NOT DISTINCT FROM CAST($expected AS $type) AS ok";
    $stmt = $conn->prepare("SELECT $assert FROM (SELECT $" . "v AS v)");
    $stmt->bindValue('v', $value);
    $checks = [$stmt->execute()->fetchRow()['ok']];
    $checks[] = $conn->execute("SELECT $assert FROM (SELECT ? AS v)", [$value])->fetchRow()['ok'];
    $table = "typed_$index";
    $conn->query("CREATE TABLE $table (v $type)");
    $app = $conn->appender($table);
    $app->appendRow([$value]);
    $app->beginRow();
    $app->append($value);
    $app->endRow();
    $app->close();
    foreach ($conn->query("SELECT $assert FROM $table")->fetchAll() as $row) {
        $checks[] = $row['ok'];
    }
    if ($checks !== [true, true, true, true]) {
        throw new RuntimeException("$type ingestion mismatch: " . json_encode($checks));
    }
    // Typed NULL must preserve native target metadata in all ingestion paths.
    $null = new Value($type, null);
    $nullStmt = $conn->prepare('SELECT typeof($v) AS t, $v IS NULL AS n');
    $nullStmt->bindValue('v', $null);
    $boundNull = $nullStmt->execute()->fetchRow();
    $nullApp = $conn->appender($table);
    $nullApp->appendRow([$null]);
    $nullApp->beginRow();
    $nullApp->append($null);
    $nullApp->endRow();
    $nullApp->close();
    $nullCount = $conn->query("SELECT count(*) AS n FROM $table WHERE v IS NULL")->fetchRow()['n'];
    $nullRow = $conn->execute('SELECT typeof(?) AS t, ? IS NULL AS n', [$null, $null])->fetchRow();
    $nativeType = $conn->query("SELECT typeof(CAST(NULL AS $type)) AS t")->fetchRow()['t'];
    if (!$nullRow['n'] || $nullRow['t'] !== $nativeType || $boundNull !== $nullRow || $nullCount !== 2) {
        throw new RuntimeException("$type typed null mismatch");
    }
    echo $type, ": ok\n";
}
?>
--EXPECT--
BOOLEAN: ok
TINYINT: ok
SMALLINT: ok
INTEGER: ok
BIGINT: ok
UTINYINT: ok
USMALLINT: ok
UINTEGER: ok
UBIGINT: ok
HUGEINT: ok
UHUGEINT: ok
BIGNUM: ok
FLOAT: ok
DOUBLE: ok
DECIMAL(18,2): ok
VARCHAR: ok
BLOB: ok
BIT: ok
UUID: ok
JSON: ok
DATE: ok
TIME: ok
TIME_NS: ok
TIMETZ: ok
TIMESTAMP_S: ok
TIMESTAMP_MS: ok
TIMESTAMP: ok
TIMESTAMP_NS: ok
TIMESTAMPTZ: ok
INTERVAL: ok
ENUM('red', 'blue'): ok
INTEGER[]: ok
INTEGER[][]: ok
INTEGER[2]: ok
STRUCT(a INTEGER, b VARCHAR): ok
MAP(VARCHAR, INTEGER): ok
MAP(INTEGER[], VARCHAR): ok
UNION(a INTEGER, b VARCHAR): ok
VARIANT: ok
GEOMETRY: ok
