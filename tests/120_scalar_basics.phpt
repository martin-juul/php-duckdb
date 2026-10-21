--TEST--
Scalar callbacks: fixed types, constants, NULL handling, batches and connection scope
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

$db = new DuckDB\Database();
$conn = $db->connect();
$conn->query(getenv('DUCKDB_PHP_TEST_PUMP_SERVICE_ONLY') ? 'SET threads = 4' : 'SET threads = 1');
$function = new TestScalar($db->connect());
$conn->registerScalarFunction('php_add', ['BIGINT', DuckDB\BigInt::class], new DuckDB\BigInt(0), $function);
$rows = $conn->query('SELECT php_add(i, 10::BIGINT) AS v FROM (VALUES (2::BIGINT), (NULL)) t(i)')->fetchAll();
scalar_check($rows === [['v' => 12], ['v' => -1]], json_encode($rows));
scalar_check($function->binds[0] === [['BIGINT', 'BIGINT'], [1 => 10]], json_encode($function->binds));
scalar_check($function->inits >= 1 && $function->rows === 2, 'Lifecycle receives all rows');
echo "constants and null rows\n";

$conn->query('SELECT php_add(i, NULL) FROM range(2) t(i)');
$last = $function->binds[array_key_last($function->binds)];
scalar_check(array_key_exists(1, $last[1]) && $last[1][1] === null, 'Constant SQL NULL is present');
scalar_check(!array_key_exists(0, $last[1]), 'Nonconstant argument is absent');
echo "constant SQL NULL\n";

$conn->query('SELECT php_add(i, 10) FROM range(1) t(i)');
$last = $function->binds[array_key_last($function->binds)];
scalar_check($last[0] === ['BIGINT', 'INTEGER'], 'Bind sees expression types before implicit casts');
echo "expression types before casts\n";

$before = $function->rows;
$result = $conn->query('SELECT sum(php_add(range, 1)) AS total FROM range(5000)')->fetchAll();
scalar_check($result === [['total' => 12502500]], json_encode($result));
scalar_check($function->rows - $before === 5000 && $function->batches >= 3, 'Every batch is evaluated');
echo "5000 rows\n";

$before = $function->rows;
$conn->query('SELECT php_add(1, 2) FROM range(5)');
scalar_check($function->rows - $before === 5, 'Volatile constant call runs for every input row');
echo "volatile constants\n";

$error = scalar_failure(fn() => $function->borrowed->rowCount());
scalar_check($error instanceof Error, 'Borrowed input expires');
scalar_check($function->copied->capacity() > 0, 'Owned column copy survives');
echo "borrowed input and owned copy\n";

scalar_failure(fn() => $conn->registerScalarFunction('PHP_ADD', ['BIGINT'], 'BIGINT', $function));
scalar_failure(fn() => $conn->registerScalarFunction('abs', ['BIGINT'], 'BIGINT', $function));
scalar_failure(fn() => $db->connect()->query('SELECT php_add(1, 2)'));
echo "unique names and connection scope\n";
?>
--EXPECT--
constants and null rows
constant SQL NULL
expression types before casts
5000 rows
volatile constants
borrowed input and owned copy
unique names and connection scope
