--TEST--
Hardening: NUL bytes in SQL text and identifiers raise ValueError (no silent truncation)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t (i INTEGER)');

// An embedded NUL would silently truncate the statement at the C API
// boundary - it must be rejected instead.
$cases = [
    'query'          => fn() => $conn->query("SELECT 1\0; DROP TABLE t"),
    'queryStreaming' => fn() => $conn->queryStreaming("SELECT 1\0"),
    'queryAsync'     => fn() => $conn->queryAsync("SELECT 1\0"),
    'queryPending'   => fn() => $conn->queryPending("SELECT 1\0"),
    'execute'        => fn() => $conn->execute("SELECT 1\0"),
    'prepare'        => fn() => $conn->prepare("SELECT 1\0"),
    'getTableNames'  => fn() => $conn->getTableNames("SELECT 1\0"),
    'appender table' => fn() => $conn->appender("t\0"),
    'appender schema' => fn() => $conn->appender('t', "mai\0n"),
    'appender catalog' => fn() => $conn->appender('t', null, "mem\0ory"),
];
foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label: NOT REJECTED\n";
    } catch (\ValueError $e) {
        echo "$label: ValueError\n";
    }
}

// the table must still exist - the injected DDL never ran
var_dump($conn->query('SELECT count(*) c FROM t')->fetchAll()[0]['c']);
echo "done\n";
?>
--EXPECT--
query: ValueError
queryStreaming: ValueError
queryAsync: ValueError
queryPending: ValueError
execute: ValueError
prepare: ValueError
getTableNames: ValueError
appender table: ValueError
appender schema: ValueError
appender catalog: ValueError
int(0)
done
