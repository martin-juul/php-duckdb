<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\Database;
use DuckDB\DataChunk;
use DuckDB\Decimal;

function checkVectors(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$conn = $db->connect();
$conn->query("CREATE TYPE status AS ENUM ('open', 'paid', 'void')");
$conn->query('CREATE TABLE invoices(id BIGINT, status status, amount DECIMAL(10,2), lines STRUCT(sku VARCHAR, qty INTEGER)[])');

// Build columns for more invoices than one chunk holds. Plain integers are
// written directly; strings, decimals and nested arrays are cast like typed
// binding, so the ENUM, DECIMAL and STRUCT types validate their input.
$count = DuckDB\vectorSize() + 500;
$ids = $conn->createVector('BIGINT', $count);
$statuses = $conn->createVector('status', $count);
$amounts = $conn->createVector(new Decimal(null, 10, 2), $count);
$lines = $conn->createVector('STRUCT(sku VARCHAR, qty INTEGER)[]', $count);
checkVectors($statuses->type() === "ENUM('open', 'paid', 'void')", 'Catalog enum was not resolved');
checkVectors($ids->isNull(0) && $lines->get($count - 1) === null, 'New vectors must start NULL');

$ids->setValues($conn, range(1, $count));
$statuses->setValues($conn, array_map(fn(int $i) => ['open', 'paid', 'void'][$i % 3], range(0, $count - 1)));
$amounts->setValues($conn, array_map(fn(int $i) => sprintf('%d.%02d', $i, $i % 100), range(0, $count - 1)));
$lines->setValues($conn, array_map(
    fn(int $i) => $i % 10 === 0 ? [] : [['sku' => "SKU-$i", 'qty' => $i % 4 + 1]],
    range(0, $count - 1),
));

// A rejected batch converts nothing, so the vector keeps its previous rows.
try {
    $statuses->setValues($conn, ['paid', 'refunded']);
    throw new RuntimeException('Unknown ENUM label was accepted');
} catch (DuckDB\ConversionException) {
    checkVectors($statuses->toArray(0, 2) === ['open', 'paid'], 'Rejected batch changed the vector');
}
$amounts->setNull(1);
$statuses->set($conn, 2, 'paid');
checkVectors($statuses->get(2) === 'paid', 'Single-row correction was not written');

// A chunk holds at most vectorSize() rows. Copy each range into staging
// vectors, then assemble the chunk from them; chunks own copies of their rows.
$writer = $db->connect();
$appender = $writer->appender('invoices');
$columns = ['id' => $ids, 'status' => $statuses, 'amount' => $amounts, 'lines' => $lines];
$staging = [];
foreach ($columns as $name => $vector) {
    $staging[$name] = $conn->createVector($vector->type());
}
$chunks = 0;
for ($offset = 0; $offset < $count; $offset += DuckDB\vectorSize()) {
    $rows = min(DuckDB\vectorSize(), $count - $offset);
    foreach ($columns as $name => $vector) {
        $staging[$name]->copyFrom($vector, $offset, $rows);
    }
    $appender->appendChunk(DataChunk::fromVectors($staging, $rows));
    ++$chunks;
}
$appender->close();

$summary = $writer->query(
    "SELECT count(*) AS invoices, count(amount) AS priced, sum(len(lines)) AS lines,
            count(*) FILTER (WHERE status = 'void') AS voided
     FROM invoices"
)->fetchRow();
checkVectors($summary['invoices'] === $count && $summary['priced'] === $count - 1, 'Invoices were lost');

// Copy a column back out of a chunk without decoding the others.
$sample = DataChunk::fromVectors(['lines' => $lines], 3)->vector(0);
checkVectors($sample->capacity() === 3 && $sample->get(1) === [['sku' => 'SKU-1', 'qty' => 2]], 'Column copy changed');

echo "Appended {$summary['invoices']} invoices with {$summary['lines']} lines in {$chunks} chunks; "
    . "{$summary['voided']} voided\n";
