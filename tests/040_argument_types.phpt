--TEST--
API: argument type errors (TypeError) across the surface
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$result = $conn->query('SELECT 1 AS a');
$stmt = $conn->prepare('SELECT ?::INT AS a');

// Wrong scalar types raise native TypeError
$cases = [
    fn() => $conn->query([]),
    fn() => $conn->queryStreaming([]),
    fn() => $conn->prepare([]),
    fn() => $conn->execute('SELECT 1', 'not-an-array'),
    fn() => $conn->appender([]),
    fn() => $conn->getTableNames([]),
    fn() => $result->columnName('x'),
    fn() => $result->columnType([]),
    fn() => $result->fetchColumn('x'),
    fn() => $result->fetchRow('not-a-fetchmode'),
    fn() => $stmt->bindValue([], 1),
    fn() => $stmt->parameterName('x'),
    fn() => $stmt->execute('not-an-array'),
    fn() => new DuckDB\Interval([], 0, 0),
    fn() => new DuckDB\Database(':memory:', 'not-an-array'),
];
foreach ($cases as $i => $fn) {
    try {
        $fn();
        echo "$i: NO EXCEPTION\n";
    } catch (TypeError $e) {
        echo "$i: TypeError\n";
    } catch (Throwable $e) {
        echo "$i: ", get_class($e), "\n";
    }
}
?>
--EXPECT--
0: TypeError
1: TypeError
2: TypeError
3: TypeError
4: TypeError
5: TypeError
6: TypeError
7: TypeError
8: TypeError
9: TypeError
10: TypeError
11: TypeError
12: TypeError
13: TypeError
14: TypeError
