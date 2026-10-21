--TEST--
Scalar callbacks: exceptions in every phase, output validation and connection recovery
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

$db = new DuckDB\Database();
$conn = $db->connect();
$function = new TestScalar($db->connect());
$conn->registerScalarFunction('php_value', ['BIGINT'], 'BIGINT', $function);
foreach (['bind', 'init', 'execute'] as $phase) {
    $boom = new RuntimeException("scalar $phase failed");
    $function->hooks[$phase] = function () use ($boom) {
        throw $boom;
    };
    $error = scalar_failure(fn() => $conn->query('SELECT php_value(range) FROM range(3)'));
    scalar_check(str_contains($error->getMessage(), "scalar $phase failed"), $error->getMessage());
    scalar_check($error->getPrevious() === $boom, "$phase preserves the original exception");
    $function->hooks = [];
    scalar_check($conn->query('SELECT php_value(7) AS v')->fetchAll() === [['v' => 7]], 'Connection recovers');
    echo "$phase exception and recovery\n";
}

foreach (['type', 'capacity'] as $case) {
    $function->hooks['execute'] = fn(DuckDB\DataChunk $arguments) => $function->writer->createVector(
        $case === 'type' ? 'VARCHAR' : 'BIGINT',
        $case === 'capacity' ? 0 : $arguments->rowCount(),
    );
    scalar_failure(fn() => $conn->query('SELECT php_value(range) FROM range(3)'));
    $function->hooks = [];
    scalar_check($conn->query('SELECT php_value(8) AS v')->fetchAll() === [['v' => 8]], 'Connection recovers');
    echo "wrong $case and recovery\n";
}
$function->hooks['execute'] = function (DuckDB\DataChunk $arguments) use ($function) {
    $vector = $function->writer->createVector('BIGINT', $arguments->rowCount() + 3);
    $vector->setValues($function->writer, array_fill(0, $arguments->rowCount(), 42));

    return $vector;
};
scalar_check($conn->query('SELECT php_value(range) AS v FROM range(3)')->fetchAll()
    === [['v' => 42], ['v' => 42], ['v' => 42]], 'Only the first input-row-count values are used');
$function->hooks = [];
echo "larger capacity accepted\n";
?>
--EXPECT--
bind exception and recovery
init exception and recovery
execute exception and recovery
wrong type and recovery
wrong capacity and recovery
larger capacity accepted
