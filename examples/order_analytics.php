<?php
// A shop receives a daily CSV extract, then produces a finance report and
// an order-level export. Everything runs locally with deterministic data.

use DuckDB\{Database, Date, Decimal, FetchMode};

require __DIR__ . '/bootstrap.php';

$csv = <<<'CSV'
order_id,ordered_on,channel,quantity,unit_price,discount,status
1001,2026-09-01,web,2,24.95,5.00,paid
1002,2026-09-02,retail,1,49.50,0.00,paid
1003,2026-09-03,web,3,12.00,2.00,paid
1004,2026-09-04,web,1,99.00,0.00,refunded
1005,2026-09-05,retail,4,10.25,1.00,paid
1006,2026-08-31,web,1,15.00,0.00,paid
1007,2026-09-06,retail,1,20.00,0.00,pending
CSV;

$inputPath = tempnam(sys_get_temp_dir(), 'duckdb-orders-');
$exportPath = tempnam(sys_get_temp_dir(), 'duckdb-report-');
$input = null;
$output = null;

try {
    if ($inputPath === false || $exportPath === false) {
        throw new RuntimeException('Unable to create temporary CSV files');
    }

    if (file_put_contents($inputPath, $csv . "\n") === false) {
        throw new RuntimeException('Unable to write the daily extract');
    }

    $conn = (new Database(':memory:'))->connect();
    $conn->query(<<<'SQL'
        CREATE TABLE orders (
            order_id INTEGER PRIMARY KEY,
            ordered_on DATE NOT NULL,
            channel VARCHAR NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            unit_price DECIMAL(12, 2) NOT NULL,
            discount DECIMAL(12, 2) NOT NULL,
            status VARCHAR NOT NULL
        )
        SQL);

    $input = fopen($inputPath, 'rb');
    if ($input === false) {
        throw new RuntimeException('Unable to open the daily extract');
    }

    $header = fgetcsv($input, escape: '');
    if ($header !== ['order_id', 'ordered_on', 'channel', 'quantity', 'unit_price', 'discount', 'status']) {
        throw new RuntimeException('Unexpected order extract header');
    }
    $conn->beginTransaction();
    try {
        $appender = $conn->appender('orders');
        while (($row = fgetcsv($input, escape: '')) !== false) {
            if (count($row) !== 7) {
                throw new RuntimeException('Expected seven fields in each order');
            }

            $orderId = filter_var($row[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quantity = filter_var($row[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($orderId === false || $quantity === false) {
                throw new RuntimeException('Order IDs and quantities must be positive integers');
            }

            // Keep prices as decimal strings: PHP floating point never enters
            // the money calculation. Typed values also preserve DATE semantics.
            $appender->appendRow([
                $orderId,
                new Date($row[1]),
                $row[2],
                $quantity,
                new Decimal($row[4], precision: 12, scale: 2),
                new Decimal($row[5], precision: 12, scale: 2),
                $row[6],
            ]);
        }

        $appender->close();
        $conn->commit();
    } catch (Throwable $error) {
        // Destroy a still-open appender before rolling back its partial batch.
        unset($appender);
        $conn->rollBack();
        throw $error;
    }

    fclose($input);
    $input = null;

    // Bind the reporting window and status instead of interpolating SQL.
    // Discounts apply once per order; refunded and pending orders are excluded.
    $parameters = [new Date('2026-09-01'), new Date('2026-10-01'), 'paid'];
    $report = $conn->prepare(<<<'SQL'
        SELECT channel, count(*) AS orders, sum(quantity) AS units,
               sum(quantity * unit_price - discount)::VARCHAR AS net_revenue
        FROM orders
        WHERE ordered_on >= ? AND ordered_on < ? AND status = ?
        GROUP BY channel
        ORDER BY channel
        SQL)->execute($parameters);

    echo "September settled sales\n";
    $totals = $report->fetchAll();
    $expected = [
        ['channel' => 'retail', 'orders' => 2, 'units' => 5, 'net_revenue' => '89.50'],
        ['channel' => 'web', 'orders' => 2, 'units' => 5, 'net_revenue' => '78.90'],
    ];
    if ($totals !== $expected) {
        throw new RuntimeException('Unexpected settled sales totals');
    }

    foreach ($totals as $row) {
        printf("%s: %d orders, %d units, revenue %s\n",
            $row['channel'], $row['orders'], $row['units'], $row['net_revenue']);
    }

    // Stream detail rows into CSV instead of collecting a complete result in
    // PHP memory. A real integration can move the completed file to storage.
    $detail = $conn->prepare(<<<'SQL'
        SELECT order_id, ordered_on::VARCHAR AS ordered_on, channel,
               (quantity * unit_price - discount)::VARCHAR AS net_revenue
        FROM orders
        WHERE ordered_on >= ? AND ordered_on < ? AND status = ?
        ORDER BY order_id
        SQL)->executeStreaming($parameters);

    $output = fopen($exportPath, 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the report export');
    }

    if (fputcsv($output, ['order_id', 'ordered_on', 'channel', 'net_revenue'], escape: '') === false) {
        throw new RuntimeException('Unable to write the export header');
    }

    $exported = 0;
    while (($row = $detail->fetchRow(FetchMode::Num)) !== null) {
        if (fputcsv($output, $row, escape: '') === false) {
            throw new RuntimeException('Unable to write an exported order');
        }

        $exported++;
    }

    fclose($output);
    $output = null;
    if ($exported !== 4) {
        throw new RuntimeException('Expected four settled orders in the export');
    }

    echo "Exported $exported settled orders\n";
} finally {
    if (is_resource($input)) {
        fclose($input);
    }

    if (is_resource($output)) {
        fclose($output);
    }

    foreach ([$inputPath, $exportPath] as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}
