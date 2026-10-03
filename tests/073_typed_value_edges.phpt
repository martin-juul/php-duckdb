--TEST--
Typed values: numeric casts, temporal precision/settings, geometry CRS and stream invalidation
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Value;
$conn = (new DuckDB\Database())->connect();
function edge(DuckDB\Connection $conn, string $sql, array $params): void {
    if ($conn->execute($sql, $params)->fetchRow()['ok'] !== true) {
        throw new RuntimeException($sql);
    }
}
edge($conn, 'SELECT isnan(?) AND isinf(?) AND isinf(?) AS ok', [new Value('DOUBLE', NAN), new Value('FLOAT', INF), new Value('DOUBLE', -INF)]);
edge($conn, 'SELECT ? = 12.35 AND ? = -12.35 AS ok', [new Value('DECIMAL(18,2)', '12.345'), new Value('DECIMAL(18,2)', '-12.345')]);
foreach ([['UTINYINT', '-1'], ['TINYINT', '128'], ['INTEGER', '2147483648'], ['JSON', '{bad'], ['UUID', 'bad'], ['BIT', '012']] as [$type, $input]) {
    try { $conn->execute('SELECT ?', [new Value($type, $input)]); echo "invalid value accepted\n"; }
    catch (DuckDB\Exception $e) { echo "cast rejected\n"; }
}
edge($conn, "SELECT ?::VARCHAR = '12:34:56.123456789' AND ?::VARCHAR = '2026-10-03 12:34:56.123456789' AS ok", [new Value('TIME_NS', '12:34:56.123456789'), new Value('TIMESTAMP_NS', '2026-10-03 12:34:56.123456789')]);
edge($conn, 'SELECT NOT isfinite(?) AND NOT isfinite(?) AS ok', [new Value('DATE', 'infinity'), new Value('TIMESTAMP', '-infinity')]);
$local = new Value('TIMESTAMPTZ', '2026-01-01 12:00:00');
$stmt = $conn->prepare('SELECT epoch($v)::BIGINT AS n');
$stmt->bindValue('v', $local);
$conn->query("SET TimeZone = 'UTC'");
$utc = $stmt->execute()->fetchRow()['n'];
$conn->query("SET TimeZone = 'Europe/Copenhagen'");
$copenhagen = $stmt->execute()->fetchRow()['n'];
if ($utc - $copenhagen !== 3600) { throw new RuntimeException('Deferred timezone cast failed'); }
echo "numeric and temporal: ok\n";

$type = "GEOMETRY('OGC:CRS84')";
$wkb = hex2bin('0101000000000000000000F03F0000000000000040');
$wkt = new Value($type, 'POINT (1 2)');
$binary = new Value($type, new Value('BLOB', $wkb));
edge($conn, "SELECT typeof(\$v) = typeof(NULL::$type) AND \$v::VARCHAR = 'POINT (1 2)' AS ok", ['v' => $wkt]);
edge($conn, "SELECT typeof(\$v) = typeof(NULL::$type) AND \$v::VARCHAR = 'POINT (1 2)' AS ok", ['v' => $binary]);
$geometryStmt = $conn->prepare("SELECT typeof(\$v) = typeof(NULL::$type) AND \$v::VARCHAR = 'POINT (1 2)' AS ok");
foreach ([$wkt, $binary] as $geometry) {
    $geometryStmt->bindValue('v', $geometry);
    if ($geometryStmt->execute()->fetchRow()['ok'] !== true) { throw new RuntimeException('Geometry statement failed'); }
}
$conn->query("CREATE TABLE geo (g $type)");
$app = $conn->appender('geo');
foreach ([$wkt, $binary] as $geometry) {
    $app->appendRow([$geometry]);
    $app->beginRow();
    $app->append($geometry);
    $app->endRow();
}
$app->close();
if ($conn->query("SELECT count(*) AS n FROM geo WHERE typeof(g) = typeof(NULL::$type) AND g::VARCHAR = 'POINT (1 2)'")->fetchRow()['n'] !== 4) { throw new RuntimeException('Geometry appender failed'); }
echo "geometry CRS: ok\n";

$stmt = $conn->prepare('SELECT $v');
$stream = $conn->queryStreaming('SELECT i FROM range(100000) t(i)');
$first = $stream->fetchRow();
$stmt->bindValue('v', new Value('INTEGER', 42));
$second = $stream->fetchRow();
if ($first['i'] !== 0 || $second['i'] !== 1) { throw new RuntimeException('Binding invalidated stream'); }
$stmt->execute();
try { while ($stream->fetchRow() !== null) {} echo "stream remained valid\n"; }
catch (DuckDB\Exception $e) { echo "execution invalidated stream\n"; }
$conn->query('CREATE TABLE streamed (v INTEGER)');
$app = $conn->appender('streamed');
$stream = $conn->queryStreaming('SELECT i FROM range(100000) t(i)');
$stream->fetchRow();
$app->appendRow([new Value('INTEGER', 42)]);
try { while ($stream->fetchRow() !== null) {} echo "appender stream remained valid\n"; }
catch (DuckDB\Exception $e) { echo "appender conversion invalidated stream\n"; }
$app->close();
?>
--EXPECT--
cast rejected
cast rejected
cast rejected
cast rejected
cast rejected
cast rejected
numeric and temporal: ok
geometry CRS: ok
execution invalidated stream
appender conversion invalidated stream
