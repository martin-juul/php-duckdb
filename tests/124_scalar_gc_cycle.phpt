--TEST--
Scalar callbacks: registrations retain functions and connection cycles are collected
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

$db = new DuckDB\Database();
foreach (['registered only', 'after execution'] as $index => $case) {
    $conn = $db->connect();
    $function = new TestScalar($db->connect());
    $function->hooks['bind'] = function () use ($conn) {
    };
    $name = 'php_value_' . $index;
    $conn->registerScalarFunction($name, ['BIGINT'], 'BIGINT', $function);
    $watch = WeakReference::create($function);
    unset($function);
    gc_collect_cycles();
    scalar_check($watch->get() !== null, 'Registration retains its function');
    if ($case === 'after execution') {
        scalar_check($conn->query("SELECT $name(42) AS v")->fetchAll() === [['v' => 42]], 'Retained function runs');
    }
    unset($conn);
    gc_collect_cycles();
    scalar_check($watch->get() === null, 'Connection cycle is collected');
    echo "$case: collected\n";
}
$conn = $db->connect();
scalar_failure(fn() => $conn->registerScalarFunction('php_value_0', ['BIGINT'], 'BIGINT', new TestScalar($db->connect())));
echo "retired names remain reserved\n";
?>
--EXPECT--
registered only: collected
after execution: collected
retired names remain reserved
