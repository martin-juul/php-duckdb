--TEST--
Result: temporal infinities, pre-epoch nanoseconds and timezone offset seconds
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query("SET TimeZone = 'UTC'");
$cases = [
    'TIMESTAMP_S positive infinity' => ["TIMESTAMP_S 'infinity'", 'infinity'],
    'TIMESTAMP_S negative infinity' => ["TIMESTAMP_S '-infinity'", '-infinity'],
    'TIMESTAMP_MS positive infinity' => ["TIMESTAMP_MS 'infinity'", 'infinity'],
    'TIMESTAMP_MS negative infinity' => ["TIMESTAMP_MS '-infinity'", '-infinity'],
    // Match DuckDB's ns-to-us cast: integer division truncates toward the epoch.
    'TIMESTAMP_NS before epoch' => ["TIMESTAMP_NS '1969-12-31 23:59:59.999999999'",
        new DateTimeImmutable('1970-01-01 00:00:00 UTC')],
    'TIMETZ positive offset seconds' => ["TIMETZ '12:34:56.123456+02:03:04'", '12:34:56.123456+02:03:04'],
    'TIMETZ negative offset seconds' => ["TIMETZ '12:34:56.123456-02:03:04'", '12:34:56.123456-02:03:04'],
];
function temporal_comparison(mixed $value): mixed {
    return $value instanceof DateTimeImmutable
        ? [get_class($value), $value->format('Y-m-d H:i:s.u e')] : $value;
}
$failures = [];
foreach ($cases as $label => [$expression, $expected]) {
    $sql = "SELECT $expression AS v WHERE ?::INTEGER = 1";
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
    foreach ($paths as $path => $run) {
        try {
            $actual = $run()->fetchRow()['v'];
            if (temporal_comparison($actual) !== temporal_comparison($expected)) {
                $failures[] = "$label/$path: " . var_export(temporal_comparison($actual), true);
            }
        } catch (Throwable $e) {
            $failures[] = "$label/$path: " . get_class($e) . ': ' . $e->getMessage();
        }
    }
    echo $label, ": verified across execution paths\n";
}
if ($failures !== []) { throw new RuntimeException(implode("\n", $failures)); }
?>
--EXPECT--
TIMESTAMP_S positive infinity: verified across execution paths
TIMESTAMP_S negative infinity: verified across execution paths
TIMESTAMP_MS positive infinity: verified across execution paths
TIMESTAMP_MS negative infinity: verified across execution paths
TIMESTAMP_NS before epoch: verified across execution paths
TIMETZ positive offset seconds: verified across execution paths
TIMETZ negative offset seconds: verified across execution paths
