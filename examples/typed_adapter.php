<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\{Database, Decimal, Integer, ListValue, Value};

// Adapter-contract fixture; no Doctrine dependency or connection is required
// by custom type conversion. A separate DBAL driver can pass the result through.
final class MoneyType
{
    public function convertToDatabaseValue(string $decimal): Value
    {
        return new Decimal($decimal, precision: 18, scale: 2);
    }
}

$converted = (new MoneyType())->convertToDatabaseValue('123.456');
$conn = (new Database())->connect();
$stmt = $conn->prepare('SELECT $amount::VARCHAR AS amount, typeof($amount) AS type');
$stmt->bindValue('amount', $converted);
$row = $stmt->execute()->fetchRow();
if ($row !== ['amount' => '123.46', 'type' => 'DECIMAL(18,2)']) {
    throw new RuntimeException('Typed adapter contract failed');
}

// A LIST is one parameter. DBAL array expansion is a separate SQL rewrite
// into multiple placeholders and must not unpack this wrapper.
$list = new ListValue([1, 2, 3], Integer::class);
if ($conn->execute('SELECT len(?) AS n', [$list])->fetchRow()['n'] !== 3) {
    throw new RuntimeException('LIST adapter contract failed');
}
echo "Typed adapter contract passed\n";
