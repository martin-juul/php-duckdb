--TEST--
Scalar callbacks: prepared, streaming, pending, Fiber and multithreaded execution paths
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

$db = new DuckDB\Database();
$conn = $db->connect();
$conn->query('SET threads = 4');
$function = new TestScalar($db->connect());
$conn->registerScalarFunction('php_value', ['BIGINT'], 'BIGINT', $function);
$sql = 'SELECT php_value(range) AS v FROM range(5000) ORDER BY v';
$paths = [
    'query' => fn() => $conn->query($sql),
    'prepared' => fn() => $conn->prepare($sql)->execute(),
    'streaming' => fn() => $conn->queryStreaming($sql),
    'prepared streaming' => fn() => $conn->prepare($sql)->executeStreaming(),
    'pending' => fn() => $conn->queryPending($sql)->await(),
    'fiber' => function () use ($conn, $sql) {
        $fiber = new Fiber(fn() => $conn->queryPending($sql)->suspend());
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        return $fiber->getReturn();
    },
];
foreach ($paths as $name => $run) {
    $before = $function->rows;
    $result = $run();
    scalar_check(array_column($result->fetchAll(), 'v') === range(0, 4999), "$name results");
    scalar_check($function->rows - $before === 5000, "$name callbacks");
    echo "$name\n";
}

$statement = $conn->prepare('SELECT php_value(?::BIGINT) AS v');
foreach ([7, 8, 9] as $value) {
    scalar_check($statement->execute([$value])->fetchAll() === [['v' => $value]], 'Prepared reuse');
}
echo "prepared reuse\n";

scalar_failure(fn() => $conn->queryAsync('SELECT php_value(1)')->await());
scalar_failure(fn() => $statement->executeAsync([1]));
scalar_check($conn->query('SELECT 1 AS v')->fetchAll() === [['v' => 1]], 'Connection recovers after async rejection');
echo "async rejected\n";
?>
--EXPECT--
query
prepared
streaming
prepared streaming
pending
fiber
prepared reuse
async rejected
