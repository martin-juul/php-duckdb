--TEST--
Types: canonical type names in column/parameter metadata
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");

// Result::columns() renders full parameterized type names
$result = $conn->query("SELECT
    1::DECIMAL(10,3) AS d,
    [1]::INT[] AS l,
    {'a': 1} AS s,
    MAP {'k': 1} AS m,
    [1,2]::INT[2] AS a,
    1::UNION(n INT, t VARCHAR) AS u,
    'happy'::mood AS e,
    [[1]]::INT[][] AS lol,
    1::HUGEINT AS h,
    '2024-01-01'::DATE AS dt");
foreach ($result->columns() as $column) {
    echo $column['name'], ' => ', $column['type'], "\n";
}

// Statement::columnType / parameterType use the same rendering
$stmt = $conn->prepare('SELECT ?::DECIMAL(18,2) AS p');
echo $stmt->parameterType(1), "\n";
echo $stmt->columnType(0), "\n";
$stmt = $conn->prepare('SELECT [1]::INT[] AS l');
echo $stmt->columnType(0), "\n";

// Result::columnType
$result = $conn->query("SELECT MAP {'k': [1]} AS nested");
echo $result->columnType(0), "\n";
?>
--EXPECT--
d => DECIMAL(10,3)
l => INTEGER[]
s => STRUCT(a INTEGER)
m => MAP(VARCHAR, INTEGER)
a => INTEGER[2]
u => UNION(n INTEGER, t VARCHAR)
e => ENUM('sad', 'ok', 'happy')
lol => INTEGER[][]
h => HUGEINT
dt => DATE
DECIMAL(18,2)
DECIMAL(18,2)
INTEGER[]
MAP(VARCHAR, INTEGER[])
