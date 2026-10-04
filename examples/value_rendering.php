<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\{CatalogValue, Database, Decimal, Integer, ListValue, TimestampTz, Varchar};

$conn = (new Database())->connect();
$amount = new Decimal('12.345', precision: 18, scale: 2);
echo 'amount: ', $amount->toString($conn), "\n";
echo 'empty list: ', (new ListValue([], Integer::class))->toString($conn), "\n";
echo 'missing: ', (new Integer(null))->toString($conn), "\n";

$conn->query("CREATE TYPE mood AS ENUM ('happy', 'quiet')");
echo 'catalog value: ', (new CatalogValue('happy', name: 'mood'))->toString($conn), "\n";

$conn->query("SET TimeZone = 'Europe/Copenhagen'");
echo 'local time: ', (new TimestampTz('2024-01-01 12:00:00+00'))->toString($conn), "\n";

// Display strings preserve bytes. Encode them explicitly if the log needs text.
echo 'binary string: ', bin2hex((new Varchar("a\0b"))->toString($conn)), "\n";

// Rendering does not consume the wrapper; it can still be bound normally.
$statement = $conn->prepare('SELECT ?::VARCHAR AS amount');
echo 'bound amount: ', $statement->execute([$amount])->fetchRow()['amount'], "\n";
