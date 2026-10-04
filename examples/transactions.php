<?php
// Transfer exact amounts in integer cents; reject invalid input before committing.

use DuckDB\Connection;
use DuckDB\Database;

require __DIR__ . '/bootstrap.php';

$conn = (new Database())->connect();
$conn->query('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance_cents BIGINT NOT NULL CHECK (balance_cents >= 0))');
$conn->execute('INSERT INTO accounts VALUES (?, ?)', [1, 100_000]);
$conn->execute('INSERT INTO accounts VALUES (?, ?)', [2, 50_000]);

function transfer(Connection $conn, int $from, int $to, int $amountCents): void
{
    if ($amountCents <= 0 || $from === $to) {
        throw new InvalidArgumentException('Use a positive amount and two different accounts');
    }

    $conn->beginTransaction();
    try {
        $accounts = $conn->execute('SELECT count(*) FROM accounts WHERE id IN (?, ?)', [$from, $to])->fetchColumn();
        if ($accounts !== 2) {
            throw new RuntimeException('Both transfer accounts must exist');
        }

        // The conditional update checks funds in the same statement as the debit.
        $debit = $conn->execute(
            'UPDATE accounts SET balance_cents = balance_cents - ? WHERE id = ? AND balance_cents >= ?',
            [$amountCents, $from, $amountCents],
        );
        if ($debit->rowsChanged() !== 1) {
            throw new RuntimeException('Insufficient funds');
        }

        $credit = $conn->execute('UPDATE accounts SET balance_cents = balance_cents + ? WHERE id = ?', [$amountCents, $to]);
        if ($credit->rowsChanged() !== 1) {
            throw new RuntimeException('Destination account disappeared');
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollBack();
        throw $error;
    }
}

transfer($conn, 1, 2, 25_000);
$expected = [['id' => 1, 'balance_cents' => 75_000], ['id' => 2, 'balance_cents' => 75_000]];
if ($conn->query('SELECT * FROM accounts ORDER BY id')->fetchAll() !== $expected) {
    throw new RuntimeException('Committed transfer changed the total balance');
}

foreach ([[1, 999, 100], [1, 2, 100_000], [1, 2, -100]] as [$from, $to, $amount]) {
    $rejected = false;
    try {
        transfer($conn, $from, $to, $amount);
    } catch (InvalidArgumentException | RuntimeException $error) {
        $rejected = true;
        echo 'rejected transfer: ', $error->getMessage(), "\n";
    }

    if (!$rejected) {
        throw new RuntimeException('Invalid transfer unexpectedly succeeded');
    }

    if ($conn->query('SELECT * FROM accounts ORDER BY id')->fetchAll() !== $expected) {
        throw new RuntimeException('Rejected transfer changed an account balance');
    }
}

foreach ($conn->query('SELECT * FROM accounts ORDER BY id') as $row) {
    printf("account %d: %d cents\n", $row['id'], $row['balance_cents']);
}

// Nested transactions are rejected with a typed error.
$conn->beginTransaction();
try {
    $conn->beginTransaction();
} catch (DuckDB\TransactionException $error) {
    echo 'nested transaction rejected (ErrorType::', $error->getErrorType()->name, ")\n";
} finally {
    $conn->rollBack();
}
