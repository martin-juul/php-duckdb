<?php
// Transactions with PDO-style helpers.

use DuckDB\Database;
use DuckDB\Exception;

require __DIR__ . '/bootstrap.php';

$db = new Database(':memory:');
$conn = $db->connect();

$conn->query('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER)');
$conn->execute('INSERT INTO accounts VALUES (?, ?)', [1, 1000]);
$conn->execute('INSERT INTO accounts VALUES (?, ?)', [2, 500]);

function transfer($conn, int $from, int $to, int $amount): void
{
    $conn->beginTransaction();
    try {
        $conn->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$amount, $from]);
        $conn->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$amount, $to]);
        $conn->commit();
        echo "transfer of $amount committed\n";
    } catch (Exception $e) {
        $conn->rollBack();
        echo "transfer rolled back: {$e->getMessage()}\n";
        throw $e;
    }
}

transfer($conn, 1, 2, 250);

foreach ($conn->query('SELECT * FROM accounts ORDER BY id') as $row) {
    printf("account %d: %d\n", $row['id'], $row['balance']);
}

// Nested transactions are rejected with a typed error:
$conn->beginTransaction();
try {
    $conn->beginTransaction();
} catch (DuckDB\TransactionException $e) {
    echo 'nested transaction rejected (ErrorType::', $e->getErrorType()->name, ")\n";
}
$conn->rollBack();
