--TEST--
Scalar callbacks: active-connection use and Fiber suspension are rejected in every phase
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
    $function->hooks[$phase] = fn() => $conn->query('SELECT 1');
    scalar_failure(fn() => $conn->query('SELECT php_value(range) FROM range(2)'));
    $function->hooks = [];
    scalar_check($conn->query('SELECT php_value(9) AS v')->fetchAll() === [['v' => 9]], 'Connection recovers');
    echo "$phase reentry rejected\n";
}

foreach (['bind', 'init', 'execute'] as $phase) {
    $function->hooks[$phase] = fn() => Fiber::suspend('must not suspend');
    $fiber = new Fiber(function () use ($conn) {
        return scalar_failure(fn() => $conn->query('SELECT php_value(range) FROM range(2)'));
    });
    $fiber->start();
    scalar_check($fiber->isTerminated(), "$phase suspended the callback");
    scalar_check($fiber->getReturn() instanceof Throwable, 'Suspension fails');
    $function->hooks = [];
    scalar_check($conn->query('SELECT php_value(9) AS v')->fetchAll() === [['v' => 9]], 'Connection recovers');
    echo "$phase suspension rejected\n";
}
?>
--EXPECT--
bind reentry rejected
init reentry rejected
execute reentry rejected
bind suspension rejected
init suspension rejected
execute suspension rejected
