<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\CopyToFunction;
use DuckDB\CopyToWriter;
use DuckDB\Database;
use DuckDB\DataChunk;
use DuckDB\FetchMode;

function checkCopy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * `COPY ... TO 'report.md' (FORMAT markdown, COLUMNS [...])` writes a Markdown
 * table. COPY functions do not receive column names, so the statement passes
 * the header as an option and bind() checks it against the columns.
 */
final class MarkdownTable implements CopyToFunction
{
    public function bind(array $columnTypes, array $options): void
    {
        $columns = $options['COLUMNS'] ?? null;
        if (!is_array($columns) || count($columns) !== count($columnTypes)) {
            throw new InvalidArgumentException(sprintf(
                'COLUMNS must name all %d columns (%s)',
                count($columnTypes),
                implode(', ', $columnTypes),
            ));
        }
    }

    public function open(string $path, array $columnTypes, array $options): CopyToWriter
    {
        return new MarkdownWriter($path, $options['COLUMNS'], $options['TITLE'] ?? null);
    }
}

final class MarkdownWriter implements CopyToWriter
{
    /** @var resource */
    private $handle;
    private int $rows = 0;

    public function __construct(string $path, array $columns, ?string $title)
    {
        $this->handle = fopen($path, 'w');
        if ($title !== null) {
            fwrite($this->handle, "## {$title}\n\n");
        }
        fwrite($this->handle, '| ' . implode(' | ', $columns) . " |\n");
        fwrite($this->handle, str_repeat('| --- ', count($columns)) . "|\n");
    }

    public function write(DataChunk $batch): void
    {
        // The batch is only valid during this call; decode what is needed now.
        foreach ($batch->toRows(FetchMode::Num) as $row) {
            $cells = array_map(fn($value) => str_replace('|', '\|', (string) ($value ?? '')), $row);
            fwrite($this->handle, '| ' . implode(' | ', $cells) . " |\n");
            ++$this->rows;
        }
    }

    public function close(): void
    {
        fwrite($this->handle, "\n{$this->rows} rows\n");
        fclose($this->handle);
    }

    public function abort(Throwable $reason): void
    {
        // DuckDB removes the incomplete file after the statement fails.
        fclose($this->handle);
    }
}

$dir = sys_get_temp_dir() . '/duckdb-copy-example-' . getmypid();
mkdir($dir);
register_shutdown_function(function () use ($dir) {
    array_map('unlink', glob("$dir/*") ?: []);
    rmdir($dir);
});

$db = new Database();
$conn = $db->connect();
$conn->registerCopyToFunction('markdown', new MarkdownTable());
$conn->query("CREATE TABLE sales AS
    SELECT 'region-' || (i % 4) AS region, (i * 7 % 100)::DECIMAL(10,2) AS amount
    FROM range(5000) t(i)");

// The format streams every batch of a large result through PHP.
$conn->query("COPY (SELECT region, sum(amount) AS total, count(*) AS orders FROM sales GROUP BY region ORDER BY region)
    TO '$dir/summary.md' (FORMAT markdown, COLUMNS ['Region', 'Total', 'Orders'], TITLE 'Sales by region')");
$summary = file_get_contents("$dir/summary.md");
checkCopy(str_contains($summary, '| region-0 | 60000.00 | 1250 |') && str_ends_with($summary, "4 rows\n"), $summary);

// A matching file extension selects the format too; prepared statements run it
// once per execution.
$detail = $conn->prepare("COPY (SELECT region, amount FROM sales WHERE region = ? ORDER BY amount DESC LIMIT 3)
    TO '$dir/top.markdown' (COLUMNS ['Region', 'Amount'])");
foreach (['region-1', 'region-2'] as $region) {
    $detail->execute([$region]);
    checkCopy(substr_count(file_get_contents("$dir/top.markdown"), "| {$region} |") === 3, "Top rows of {$region}");
}

// bind() rejects the statement before anything runs; the handler's exception
// is chained.
try {
    $conn->query("COPY (SELECT region FROM sales) TO '$dir/bad.md' (FORMAT markdown)");
    throw new LogicException('The statement was not rejected');
} catch (DuckDB\BinderException $error) {
    checkCopy($error->getPrevious() instanceof InvalidArgumentException, 'The bind() exception is chained');
}
checkCopy(!file_exists("$dir/bad.md"), 'A rejected statement creates no file');

// Other connections, including other connections to this database, do not see
// the format.
try {
    $db->connect()->query("COPY (SELECT 1) TO '$dir/other.md' (FORMAT markdown)");
    throw new LogicException('Another connection used the format');
} catch (DuckDB\CatalogException) {
}

echo 'Wrote a Markdown summary of ', substr_count($summary, "\n| region-"), " regions and top rows for 2 regions\n";
