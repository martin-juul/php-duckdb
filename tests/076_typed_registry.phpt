--TEST--
Typed capture registry: one native function per database, transactions, connections and recovery
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function registryNames(DuckDB\Connection $conn): array {
    return array_column($conn->query("SELECT function_name FROM duckdb_functions()
        WHERE starts_with(function_name, '__php_duckdb_value_capture_')
        ORDER BY function_name")->fetchAll(), 'function_name');
}
function requireRegistry(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$db = new DuckDB\Database();
$observer = $db->connect();
requireRegistry(count(registryNames($observer)) === 1, 'Connection must expose its transaction-safe registry');
for ($i = 0; $i < 24; $i++) {
    $conn = $db->connect();
    requireRegistry($conn->execute('SELECT ? AS n', [new DuckDB\Integer($i)])->fetchRow()['n'] === $i,
        'Sequential connection captured wrong value');
    $conn->close();
    unset($conn);
}
$names = registryNames($observer);
requireRegistry(count($names) === 1, 'Sequential connections accumulated private functions');
echo "one registry across 24 connections\n";
$name = '"' . str_replace('"', '""', $names[0]) . '"';
requireRegistry($observer->query("SELECT $name(1, 'stale', NULL) AS ok")->fetchRow()['ok'] === true,
    'Private function without an operation must remain harmless');
echo "inactive private function harmless\n";

// Begin the transaction before the first typed conversion on a fresh database.
// Registration must not commit it or move type resolution to another connection.
$transactionDb = new DuckDB\Database();
$transactionConn = $transactionDb->connect();
$transactionConn->query('BEGIN');
$transactionConn->query("CREATE TYPE local_enum AS ENUM('local', 'other')");
$transactionConn->query('CREATE TABLE transaction_only(n INTEGER)');
$transactionConn->query('INSERT INTO transaction_only VALUES(1)');
$local = new DuckDB\CatalogValue('local', 'local_enum');
requireRegistry($transactionConn->execute(
    "SELECT ?::VARCHAR = 'local' AND (SELECT count(*) FROM transaction_only) = 1 AS ok",
    [$local])->fetchRow()['ok'] === true, 'Transaction-local type/value was not visible');
$transactionConn->query('ROLLBACK');
try { $transactionConn->execute('SELECT ?', [$local]); throw new RuntimeException('Local type survived rollback'); }
catch (DuckDB\CatalogException) { echo "transaction-local type rolled back\n"; }
try { $transactionConn->query('SELECT * FROM transaction_only'); throw new RuntimeException('Table survived rollback'); }
catch (DuckDB\CatalogException) { echo "transaction-local table rolled back\n"; }
requireRegistry($transactionConn->execute('SELECT ? AS n', [new DuckDB\Integer(7)])->fetchRow()['n'] === 7,
    'Registry did not survive consumer rollback');
requireRegistry(count(registryNames($transactionConn)) === 1, 'Rollback recreated private registry');
echo "first conversion preserves transaction and registry\n";

$a = $db->connect();
$b = $db->connect();
$stmtA = $a->prepare('SELECT ? AS marker, sum(i) AS total FROM range(1000000) t(i)');
$stmtB = $b->prepare('SELECT ? AS marker, sum(i) AS total FROM range(1000000) t(i)');
$pendingA = $stmtA->executeAsync([new DuckDB\Integer(11)]);
$pendingB = $stmtB->executeAsync([new DuckDB\Varchar('second')]);
$rowA = $pendingA->await()->fetchRow();
$rowB = $pendingB->await()->fetchRow();
requireRegistry($rowA === ['marker' => 11, 'total' => 499999500000], 'First connection capture mismatch');
requireRegistry($rowB === ['marker' => 'second', 'total' => 499999500000], 'Second connection capture mismatch');
echo "async connections retain separate typed values\n";

// A worker can bind the discovered private function on one connection while
// another connection captures values. An inactive connection has no destination.
$stalePending = $a->queryAsync("SELECT $name(1) AS ok, sum(i) AS total FROM range(1000000) t(i)");
for ($i = 0; $i < 8; $i++) {
    try { $b->execute('SELECT ?', [new DuckDB\Integer('bad')]); throw new RuntimeException('Invalid cast accepted'); }
    catch (DuckDB\ConversionException) {}
    try { $b->execute('SELECT ?', [new DuckDB\Struct([], ['x' => 'INTEGER'])]); throw new RuntimeException('Invalid shape accepted'); }
    catch (ValueError) {}
    requireRegistry($b->execute('SELECT ? AS n', [new DuckDB\Integer($i)])->fetchRow()['n'] === $i,
        'Failure retained a stale capture operation');
}
$staleRow = $stalePending->await()->fetchRow();
requireRegistry($staleRow === ['ok' => true, 'total' => 499999500000], 'Cross-connection inactive callback changed output');
requireRegistry(count(registryNames($observer)) === 1, 'Async execution or failures recreated registry');
echo "failure recovery and cross-connection callback safe\n";
$file = tempnam(sys_get_temp_dir(), 'duckdb-registry-shared-');
unlink($file);
$fileDb = new DuckDB\Database($file);
$fileObserver = $fileDb->connect();
try {
    for ($i = 0; $i < 12; $i++) {
        $reopenedDb = new DuckDB\Database($file);
        $reopenedConn = $reopenedDb->connect();
        requireRegistry($reopenedConn->execute('SELECT ? AS n', [new DuckDB\Integer($i)])->fetchRow()['n'] === $i,
            'Reopened file database captured wrong value');
        $reopenedConn->close();
        unset($reopenedConn, $reopenedDb);
    }
    requireRegistry(count(registryNames($fileObserver)) === 1, 'Shared file wrappers accumulated registries');
    echo "one registry across shared file wrappers\n";
} finally {
    $fileObserver->close();
    unset($fileObserver, $fileDb);
    unlink($file);
}

?>
--EXPECT--
one registry across 24 connections
inactive private function harmless
transaction-local type rolled back
transaction-local table rolled back
first conversion preserves transaction and registry
async connections retain separate typed values
failure recovery and cross-connection callback safe
one registry across shared file wrappers
