--TEST--
Statement: parameter and column metadata
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE meta (i INTEGER, s VARCHAR, d DOUBLE, b BOOLEAN)');

// Parameter types resolve from the target table schema (like duckdb-java)
$stmt = $conn->prepare('INSERT INTO meta VALUES (?, ?, ?, ?)');
var_dump($stmt->parameterCount());
for ($i = 1; $i <= 4; $i++) {
    echo $stmt->parameterName($i), ': ', $stmt->parameterType($i), "\n";
}
echo $stmt->statementType(), "\n";

// Result column metadata
$stmt = $conn->prepare('SELECT i, s FROM meta WHERE i = ?::INTEGER');
var_dump($stmt->statementType());
var_dump($stmt->columnCount());
echo $stmt->columnName(0), ': ', $stmt->columnType(0), "\n";
echo $stmt->columnName(1), ': ', $stmt->columnType(1), "\n";
var_dump($stmt->parameterType(1));

$stmt = $conn->prepare('CREATE TABLE x (a INT)');
var_dump($stmt->statementType());
var_dump($stmt->columnCount()); // DDL reports a single status column

// Out-of-range column index
$stmt = $conn->prepare('SELECT 1 AS one');
try {
    $stmt->columnName(5);
} catch (ValueError $e) {
    echo 'ValueError caught', "\n";
}
?>
--EXPECT--
int(4)
1: INTEGER
2: VARCHAR
3: DOUBLE
4: BOOLEAN
INSERT
string(6) "SELECT"
int(2)
i: INTEGER
s: VARCHAR
string(7) "INTEGER"
string(6) "CREATE"
int(1)
ValueError caught
