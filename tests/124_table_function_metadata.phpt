--TEST--
Connection: table-function catalog overloads and bound output schema
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

$connection = (new DuckDB\Database())->connect();
$overloads = $connection->getTableFunctions('range');
check(count($overloads) >= 3, 'Expected separate range overloads');
check($overloads === $connection->getTableFunctions('RaNgE'), 'Name matching must ignore case');
check($overloads === $connection->getTableFunctions('range'), 'Metadata order must be stable');

$integerArities = [];
foreach ($overloads as $function) {
    check(array_keys($function) === ['catalog', 'schema', 'name', 'description', 'parameters', 'parameterTypes', 'varargs'], 'Unexpected metadata shape');
    check(is_string($function['catalog']) && is_string($function['schema']), 'Expected catalog and schema strings');
    check($function['name'] === 'range', 'Unexpected function');
    check($function['description'] === null || is_string($function['description']), 'Invalid description');
    check($function['varargs'] === null || is_string($function['varargs']), 'Invalid variadic type');
    check(array_is_list($function['parameters']) && array_is_list($function['parameterTypes']), 'Expected parameter lists');
    check(count($function['parameters']) === count($function['parameterTypes']), 'Parameter names and types must be parallel');
    foreach ($function['parameters'] as $parameter) {
        check(is_string($parameter), 'Invalid parameter name');
    }
    foreach ($function['parameterTypes'] as $type) {
        check(is_string($type), 'Invalid parameter type');
    }
    if ($function['parameterTypes'] !== [] && count(array_unique($function['parameterTypes'])) === 1 && $function['parameterTypes'][0] === 'BIGINT') {
        $integerArities[] = count($function['parameterTypes']);
    }
}
sort($integerArities);
check($integerArities === [1, 2, 3], 'Missing BIGINT range overloads');
echo "overloads: ok\n";

$all = $connection->getTableFunctions();
check($all === $connection->getTableFunctions(null), 'Explicit null must list all functions');
check(count($all) > count($overloads), 'Expected complete catalog enumeration');
$allRange = array_values(array_filter($all, static fn(array $function): bool => $function['name'] === 'range'));
check($allRange === $overloads, 'Filtered and complete metadata must agree');
check($connection->getTableFunctions('abs') === [], 'Scalar functions must be excluded');
$connection->query('CREATE MACRO metadata_macro(x) AS TABLE SELECT x AS value');
check($connection->getTableFunctions('metadata_macro') === [], 'Table macros must be excluded');
check($connection->getTableFunctions('missing_table_function_124') === [], 'Unknown name must return an empty list');
check($connection->getTableFunctions("range' OR true --") === [], 'Names must be treated as data');
check($connection->getTableFunctions('') === [], 'Empty name must match no functions');
try {
    $connection->getTableFunctions("range\0hidden");
    throw new RuntimeException('Embedded NUL was accepted');
} catch (ValueError $error) {
    echo "NUL name: ok\n";
}
echo "discovery: ok\n";

$statement = $connection->prepare('SELECT * FROM range(10)');
check($statement->columnCount() === 1, 'Unexpected output column count');
check($statement->columnName(0) === 'range' && $statement->columnType(0) === 'BIGINT', 'Bound output schema is unavailable');
$connection->query('CREATE TABLE metadata_transaction (value INTEGER)');
$connection->beginTransaction();
$connection->query('INSERT INTO metadata_transaction VALUES (42)');
$connection->getTableFunctions('range');
$connection->rollBack();
check($connection->query('SELECT count(*) AS n FROM metadata_transaction')->fetchRow() === ['n' => 0], 'Metadata lookup changed the caller transaction');
echo "bound schema and transaction: ok\n";

$connection->close();
check($overloads[0]['name'] === 'range', 'Metadata snapshot must survive connection closure');
try {
    $connection->getTableFunctions('range');
    throw new RuntimeException('Closed connection was accepted');
} catch (DuckDB\ConnectionException $error) {
    echo "closed: ok\n";
}

$connection = (new DuckDB\Database())->connect();
try {
    $connection->getTableFunctions([]);
    throw new RuntimeException('Invalid name type was accepted');
} catch (TypeError $error) {
    echo "argument type: ok\n";
}
?>
--EXPECT--
overloads: ok
NUL name: ok
discovery: ok
bound schema and transaction: ok
closed: ok
argument type: ok
