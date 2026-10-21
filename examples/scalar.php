<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\Connection;
use DuckDB\Database;
use DuckDB\DataChunk;
use DuckDB\FetchMode;
use DuckDB\ScalarFunction;
use DuckDB\ScalarFunctionBinding;
use DuckDB\ScalarFunctionExecutor;
use DuckDB\Vector;

/** Apply an application-specific adjustment to each invoice amount. */
final class AdjustInvoice implements ScalarFunction
{
    public function __construct(private Connection $writer)
    {
    }

    public function bind(array $argumentTypes, array $constantArguments): ScalarFunctionBinding
    {
        // Constants are keyed by zero-based argument position. A present null
        // means SQL NULL; an absent key means a nonconstant expression.
        $constantAdjustment = array_key_exists(1, $constantArguments)
            ? $constantArguments[1]
            : null;

        return new InvoiceBinding($this->writer, $constantAdjustment);
    }
}

final class InvoiceBinding implements ScalarFunctionBinding
{
    public function __construct(private Connection $writer, private ?int $constantAdjustment)
    {
    }

    public function init(): ScalarFunctionExecutor
    {
        // DuckDB can initialize several executors for a single query.
        return new InvoiceExecutor($this->writer, $this->constantAdjustment);
    }
}

final class InvoiceExecutor implements ScalarFunctionExecutor
{
    public function __construct(private Connection $writer, private ?int $constantAdjustment)
    {
    }

    public function execute(DataChunk $arguments): Vector
    {
        $values = [];
        foreach ($arguments->toRows(FetchMode::Num) as [$cents, $adjustment]) {
            $adjustment = $this->constantAdjustment ?? $adjustment;
            $values[] = $cents === null || $adjustment === null ? null : max(0, $cents + $adjustment);
        }

        // The executing connection is unavailable inside callbacks. Use a
        // separate connection to create vectors and convert their values.
        $result = $this->writer->createVector('BIGINT', count($values));
        $result->setValues($this->writer, $values);

        return $result;
    }
}

$db = new Database();
$conn = $db->connect();
$conn->registerScalarFunction('invoice_adjust_php', ['BIGINT', 'BIGINT'], 'BIGINT', new AdjustInvoice($db->connect()));

$rows = $conn->query(<<<'SQL'
    SELECT invoice, invoice_adjust_php(cents, -100) AS adjusted_cents
    FROM (VALUES ('A', 1250::BIGINT), ('B', 50), ('C', NULL)) invoices(invoice, cents)
    ORDER BY invoice
    SQL)->fetchAll();
if ($rows !== [
    ['invoice' => 'A', 'adjusted_cents' => 1150],
    ['invoice' => 'B', 'adjusted_cents' => 0],
    ['invoice' => 'C', 'adjusted_cents' => null],
]) {
    throw new RuntimeException('Unexpected invoice adjustment: ' . json_encode($rows));
}

foreach ($rows as $row) {
    echo $row['invoice'], ': ', $row['adjusted_cents'] ?? 'NULL', "\n";
}
