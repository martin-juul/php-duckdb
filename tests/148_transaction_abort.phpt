--TEST--
Transactions: commit() throws for a transaction DuckDB aborted, and commits a healthy one as before
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function count_rows(DuckDB\Connection $conn): int
{
    return $conn->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'];
}

/** Print the class and first message line of what $operation throws. */
function report(string $label, callable $operation): void
{
    try {
        $operation();
        echo $label, ": ok\n";
    } catch (DuckDB\Exception $error) {
        echo $label, ': ', get_class($error), ' code=', $error->getCode(), ' ', strtok($error->getMessage(), "\n"), "\n";
    }
}

function fresh_connection(): DuckDB\Connection
{
    $conn = (new DuckDB\Database())->connect();
    $conn->query('CREATE TABLE t (i BIGINT)');

    return $conn;
}

echo "-- refused statements beside an undriven pending INSERT inside a transaction\n";
$conn = fresh_connection();
$conn->beginTransaction();
$pending = $conn->queryPending('INSERT INTO t SELECT * FROM range(300000)');
report('query', fn () => $conn->query('SELECT 1'));
report('commit', fn () => $conn->commit());
report('rollBack', fn () => $conn->rollBack());
var_dump($pending->await()->rowsChanged());
var_dump(count_rows($conn));
report('commit', fn () => $conn->commit());
var_dump(count_rows($conn));
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1)');
$conn->commit();
var_dump(count_rows($conn));

echo "-- SQL error inside a transaction\n";
$conn = fresh_connection();
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1)');
report('failing query', fn () => $conn->query('SELECT * FROM missing'));
report('commit', fn () => $conn->commit());
var_dump(count_rows($conn));
report('rollBack after the failed commit', fn () => $conn->rollBack());

echo "-- refused statements beside a read-only stream inside a transaction\n";
$conn = fresh_connection();
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1), (2)');
$stream = $conn->queryStreaming('SELECT range AS i FROM range(100000)');
var_dump($stream->fetchRow());
report('query', fn () => $conn->query('SELECT 1'));
report('commit', fn () => $conn->commit());
var_dump(count($stream->fetchAll()));
report('commit', fn () => $conn->commit());
var_dump(count_rows($conn));

echo "-- refused statement beside an undriven pending INSERT under autocommit\n";
$conn = fresh_connection();
$pending = $conn->queryPending('INSERT INTO t SELECT * FROM range(300000)');
report('count', fn () => count_rows($conn));
var_dump($pending->await()->rowsChanged());
var_dump(count_rows($conn));

// Its writes finish before the first row, so DuckDB does not count it as
// holding the connection.
echo "-- statements beside a streaming INSERT ... RETURNING inside a transaction\n";
$conn = fresh_connection();
$conn->beginTransaction();
$returning = $conn->queryStreaming('INSERT INTO t SELECT * FROM range(100000) RETURNING i');
var_dump($returning->fetchRow());
report('query', fn () => $conn->query('SELECT 1'));
report('commit', fn () => $conn->commit());
var_dump(count($returning->fetchAll()));
var_dump(count_rows($conn));

echo "-- healthy transaction\n";
$profile = sys_get_temp_dir() . '/duckdb_phpt_148_' . uniqid() . '.json';
$conn = fresh_connection();
$conn->query("PRAGMA enable_profiling = 'json'");
$conn->query("SET profile_output = '{$profile}'");
$conn->beginTransaction();
$inserted = $conn->query('INSERT INTO t VALUES (1), (2), (3)');
$last = $conn->query('SELECT 42 AS last_user_query');
var_dump($conn->commit());
$conn->query('PRAGMA disable_profiling');
var_dump($inserted->rowsChanged());
var_dump($last->fetchAll());
var_dump(str_contains(file_get_contents($profile), 'last_user_query'));
unlink($profile);
var_dump(count_rows($conn));

echo "-- commit without a transaction\n";
report('commit', fn () => $conn->commit());

echo "-- rollBack in the catch block of a commit() that rolled back\n";
$conn = fresh_connection();
$conn->beginTransaction();
$conn->query('INSERT INTO t VALUES (1)');
try {
    $conn->query('SELECT * FROM missing');
} catch (DuckDB\Exception $e) {
}
try {
    $conn->commit();
} catch (DuckDB\TransactionException $e) {
    $conn->rollBack();
    echo 'caught: ', $e->getMessage(), "\n";
}
var_dump(count_rows($conn));
report('second rollBack', fn () => $conn->rollBack());

echo "-- rollBack after another statement keeps its error\n";
$conn->beginTransaction();
try {
    $conn->query('SELECT * FROM missing');
} catch (DuckDB\Exception $e) {
}
report('commit', fn () => $conn->commit());
$conn->query('SELECT 1');
report('rollBack', fn () => $conn->rollBack());

echo "-- rollBack after a transaction begun through queryAsync()\n";
$conn = fresh_connection();
$conn->beginTransaction();
try {
    $conn->query('SELECT * FROM missing');
} catch (DuckDB\Exception $e) {
}
report('commit', fn () => $conn->commit());
$conn->queryAsync('BEGIN TRANSACTION')->await();
$conn->queryAsync('INSERT INTO t VALUES (1)')->await();
report('rollBack', fn () => $conn->rollBack());
var_dump(count_rows($conn));
?>
--EXPECT--
-- refused statements beside an undriven pending INSERT inside a transaction
query: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
commit: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
rollBack: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
int(300000)
int(300000)
commit: ok
int(300000)
int(300001)
-- SQL error inside a transaction
failing query: DuckDB\CatalogException code=13 Catalog Error: Table with name missing does not exist!
commit: DuckDB\TransactionException code=10 Transaction was aborted, so nothing was committed; it has been rolled back
int(0)
rollBack after the failed commit: DuckDB\TransactionException code=10 TransactionContext Error: cannot rollback - no transaction is active
-- refused statements beside a read-only stream inside a transaction
array(1) {
  ["i"]=>
  int(0)
}
query: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
commit: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
int(99999)
commit: ok
int(2)
-- refused statement beside an undriven pending INSERT under autocommit
count: DuckDB\Exception code=44 Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
int(300000)
int(300000)
-- statements beside a streaming INSERT ... RETURNING inside a transaction
array(1) {
  ["i"]=>
  int(0)
}
query: ok
commit: ok
int(99999)
int(100000)
-- healthy transaction
NULL
int(3)
array(1) {
  [0]=>
  array(1) {
    ["last_user_query"]=>
    int(42)
  }
}
bool(true)
int(3)
-- commit without a transaction
commit: DuckDB\TransactionException code=10 TransactionContext Error: cannot commit - no transaction is active
-- rollBack in the catch block of a commit() that rolled back
caught: Transaction was aborted, so nothing was committed; it has been rolled back
int(0)
second rollBack: DuckDB\TransactionException code=10 TransactionContext Error: cannot rollback - no transaction is active
-- rollBack after another statement keeps its error
commit: DuckDB\TransactionException code=10 Transaction was aborted, so nothing was committed; it has been rolled back
rollBack: DuckDB\TransactionException code=10 TransactionContext Error: cannot rollback - no transaction is active
-- rollBack after a transaction begun through queryAsync()
commit: DuckDB\TransactionException code=10 Transaction was aborted, so nothing was committed; it has been rolled back
rollBack: ok
int(0)
