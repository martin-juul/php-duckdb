<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DuckDB\Database;
use DuckDB\Interval;
use DuckDB\Value;

// A typed data dictionary: choose a wrapper for the declaration you need,
// especially when PHP's integer range, temporal precision or empty arrays
// cannot express the intended DuckDB type. Wrappers need no connection.
$billingPeriod = new Interval(months: 2, days: 3, micros: 4_000_000);
if ($billingPeriod->getMonths() !== 2 || $billingPeriod->getDays() !== 3 ||
    $billingPeriod->getMicros() !== 4_000_000) {
    throw new RuntimeException('Interval components changed');
}
if ($billingPeriod->jsonSerialize() !== ['months' => 2, 'days' => 3, 'micros' => 4_000_000]) {
    throw new RuntimeException('Interval JSON representation changed');
}
echo 'billing interval: ', (string) $billingPeriod, "\n";
echo 'interval JSON: ', json_encode($billingPeriod, JSON_THROW_ON_ERROR), "\n";
$retryDelay = Interval::fromSeconds(90.5);
if ($retryDelay->getMicros() !== 90_500_000) {
    throw new RuntimeException('Fractional seconds lost precision');
}

$dictionary = [
    'custom SQL declaration' => new Value('DECIMAL(12,2)', '19.995'),
    'enabled flag' => new DuckDB\Boolean(true),
    'signed 8-bit reading' => new DuckDB\TinyInt(-128),
    'signed 16-bit reading' => new DuckDB\SmallInt(-32768),
    'signed 32-bit reading' => new DuckDB\Integer(42),
    'signed 64-bit balance' => new DuckDB\BigInt('-9223372036854775808'),
    'unsigned 8-bit counter' => new DuckDB\UTinyInt(255),
    'unsigned 16-bit counter' => new DuckDB\USmallInt(65535),
    'unsigned 32-bit counter' => new DuckDB\UInteger('4294967295'),
    'unsigned 64-bit identifier' => new DuckDB\UBigInt('18446744073709551615'),
    'signed 128-bit aggregate' => new DuckDB\HugeInt('170141183460469231731687303715884105727'),
    'unsigned 128-bit aggregate' => new DuckDB\UHugeInt('340282366920938463463374607431768211455'),
    'arbitrary precision integer' => new DuckDB\BigNum('12345678901234567890123456789012345678901234567890'),
    '32-bit sensor value' => new DuckDB\Float32(1.5),
    '64-bit sensor value' => new DuckDB\Double(2.25),
    'customer label' => new DuckDB\Varchar("O'Brien"),
    'binary payload' => new DuckDB\Blob("a\0b"),
    'permission bits' => new DuckDB\Bit('00101'),
    'request identifier' => new DuckDB\Uuid('550e8400-e29b-41d4-a716-446655440000'),
    'JSON payload' => new DuckDB\Json('{"items":[1,null]}'),
    'invoice day' => new DuckDB\Date('2026-10-03'),
    'local time' => new DuckDB\Time('12:34:56.123456'),
    'nanosecond local time' => new DuckDB\TimeNs('12:34:56.123456789'),
    'time with offset' => new DuckDB\TimeTz('12:34:56+02:00'),
    'second timestamp' => new DuckDB\TimestampS('2026-10-03 12:34:56'),
    'millisecond timestamp' => new DuckDB\TimestampMs('2026-10-03 12:34:56.123'),
    'microsecond timestamp' => new DuckDB\Timestamp('2026-10-03 12:34:56.123456'),
    'nanosecond timestamp' => new DuckDB\TimestampNs('2026-10-03 12:34:56.123456789'),
    'timestamp with timezone' => new DuckDB\TimestampTz('2026-10-03 12:34:56+02:00'),
    'billing period' => new DuckDB\IntervalValue($billingPeriod),
    'heterogeneous payload' => new DuckDB\Variant(new DuckDB\Json('{"items":[1,"two",null]}')),
    'exact money' => new DuckDB\Decimal('12.345', precision: 18, scale: 2),
    'inline order status' => new DuckDB\Enum('paid', ['new', 'paid', 'refunded']),
    'empty identifiers' => new DuckDB\ListValue([], DuckDB\Integer::class),
    'fixed coordinate pair' => new DuckDB\ArrayValue([1.5, 2.5], DuckDB\Double::class, length: 2),
    'customer attributes' => new DuckDB\Struct(
        ['label' => 'sample', 'active' => true],
        fields: ['label' => DuckDB\Varchar::class, 'active' => DuckDB\Boolean::class],
    ),
    'string-keyed quantities' => new DuckDB\Map(
        [['key' => '01', 'value' => 2], ['key' => '1', 'value' => 3]],
        DuckDB\Varchar::class,
        DuckDB\Integer::class,
    ),
    'tagged measurement' => new DuckDB\Union(
        'offline',
        tag: 'text',
        members: ['reading' => DuckDB\Double::class, 'text' => DuckDB\Varchar::class],
    ),
    'delivery location' => new DuckDB\Geometry('POINT (1 2)', crs: 'OGC:CRS84'),
    'catalog order status' => new DuckDB\CatalogValue('paid', name: 'order_status', schema: 'main'),
    'missing integer' => new DuckDB\Integer(null),
];

$conn = (new Database())->connect();
$conn->query("SET TimeZone = 'UTC'");
$conn->query("CREATE TYPE order_status AS ENUM ('new', 'paid', 'refunded')");
foreach ($dictionary as $label => $value) {
    // Display text is for logs, never a SQL literal. Binding the original
    // wrapper preserves the declaration and nested/CRS information.
    $declaration = $value->getType();
    $display = $value->toString($conn);
    // Each dictionary entry declares its own schema. Keep a prepared
    // statement's parameter declaration stable across repeated executions.
    $statement = $conn->prepare('SELECT typeof(v) AS native_type, COALESCE(v::VARCHAR, \'NULL\') AS display FROM (SELECT $value AS v)');
    $statement->bindValue('value', $value);
    $row = $statement->execute()->fetchRow();
    $expectedType = $conn->query("SELECT typeof(CAST(NULL AS $declaration)) AS type")->fetchRow()['type'];
    if ($row['native_type'] !== $expectedType || $row['display'] !== $display) {
        throw new RuntimeException('Typed binding or display mismatch: ' . $label);
    }

    printf("%-30s %-24s %s\n", $label, $declaration, $display);
}

// A small ingestion example keeps exact money, typed empty lists and a
// structured customer record. Query results retain their usual PHP mapping.
$conn->query('CREATE TABLE invoices(amount DECIMAL(18,2), ids INTEGER[], customer STRUCT(label VARCHAR, active BOOLEAN))');
$appender = $conn->appender('invoices');
$appender->appendRow([
    $dictionary['exact money'],
    $dictionary['empty identifiers'],
    $dictionary['customer attributes'],
]);
$appender->close();
$invoice = $conn->query('SELECT amount, len(ids) AS ids, customer.label AS label FROM invoices')->fetchRow();
if ($invoice !== ['amount' => '12.35', 'ids' => 0, 'label' => 'sample']) {
    throw new RuntimeException('Typed invoice ingestion failed');
}
echo 'invoice: ', json_encode($invoice, JSON_THROW_ON_ERROR), "\n";
