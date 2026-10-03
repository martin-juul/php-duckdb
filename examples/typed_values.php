<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\{Boolean, Database, Decimal, Integer, ListValue, Struct, Varchar};

$conn = (new Database())->connect();
$amount = new Decimal('12.345', precision: 18, scale: 2);
$stmt = $conn->prepare('SELECT typeof($amount) AS type, $amount::VARCHAR AS amount');
$stmt->bindValue('amount', $amount);
var_export($stmt->execute()->fetchRow());
echo "\n";

$conn->query('CREATE TABLE typed (amount DECIMAL(18,2), ids INTEGER[], info STRUCT(label VARCHAR, active BOOLEAN))');
$appender = $conn->appender('typed');
$appender->appendRow([
    $amount,
    new ListValue([], Integer::class),
    new Struct(['active' => true, 'label' => 'sample'], fields: ['label' => Varchar::class, 'active' => Boolean::class]),
]);
$appender->close();
var_export($conn->query('SELECT amount::VARCHAR AS amount, len(ids) AS ids, info.label AS label FROM typed')->fetchRow());
echo "\n";
