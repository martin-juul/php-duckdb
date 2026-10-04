--TEST--
Result: exact UBIGINT and BIGNUM/VARIANT decoding across result contexts
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php

$conn = (new DuckDB\Database())->connect();
$maximum = '18446744073709551615';
$row = $conn->execute('SELECT ?::UBIGINT AS v', [$maximum])->fetchRow();
if ($row['v'] !== $maximum) {
    throw new RuntimeException('UBIGINT maximum was not an exact decimal string');
}
echo "UBIGINT maximum: exact string\n";
$row = $conn->execute('SELECT ?::UBIGINT AS v', [(string) PHP_INT_MAX])->fetchRow();
if ($row['v'] !== PHP_INT_MAX) {
    throw new RuntimeException('Representable UBIGINT was not a PHP integer');
}
echo "UBIGINT PHP limit: integer\n";

$cases = [
    ['BIGNUM', "'1234567890123456789012345678901234567890'::BIGNUM",
        '1234567890123456789012345678901234567890'],
    ['VARIANT', "'{\"a\":[1,null]}'::JSON::VARIANT", '{"a":[1,null]}'],
];
foreach ($cases as [$type, $expression, $expected]) {
    foreach (['buffered' => false, 'streaming' => true] as $context => $streaming) {
        $result = $streaming
            ? $conn->queryStreaming("SELECT $expression AS v")
            : $conn->query("SELECT $expression AS v");
        if ($result->fetchRow() !== ['v' => $expected]) {
            throw new RuntimeException("$type $context decoding lost information");
        }
        echo $type, ' ', $context, ": exact rendering\n";
    }
    $row = $conn->query("SELECT [$expression] AS v")->fetchRow();
    if ($row !== ['v' => [$expected]]) {
        throw new RuntimeException("Nested LIST $type failed");
    }
    echo $type, " nested LIST: exact rendering\n";
    $row = $conn->query("SELECT {'field': $expression} AS v")->fetchRow();
    if ($row !== ['v' => ['field' => $expected]]) {
        throw new RuntimeException("Nested STRUCT $type failed");
    }
    echo $type, " nested STRUCT: exact rendering\n";

    $row = $conn->queryStreaming("SELECT NULL::$type AS v")->fetchRow();
    if ($row !== ['v' => null]) {
        throw new RuntimeException("Streaming NULL $type failed");
    }
    $row = $conn->query("SELECT [NULL::$type] AS v")->fetchRow();
    if ($row !== ['v' => [null]]) {
        throw new RuntimeException("Nested NULL $type failed");
    }
    echo $type, ": NULL remains supported\n";
}
?>
--EXPECT--
UBIGINT maximum: exact string
UBIGINT PHP limit: integer
BIGNUM buffered: exact rendering
BIGNUM streaming: exact rendering
BIGNUM nested LIST: exact rendering
BIGNUM nested STRUCT: exact rendering
BIGNUM: NULL remains supported
VARIANT buffered: exact rendering
VARIANT streaming: exact rendering
VARIANT nested LIST: exact rendering
VARIANT nested STRUCT: exact rendering
VARIANT: NULL remains supported
