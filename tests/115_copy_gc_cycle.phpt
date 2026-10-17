--TEST--
COPY TO: a format that references its connection is collected as a cycle
--DESCRIPTION--
Kept apart from the other COPY tests, and without stream writes: on the True
Async runtime (0.7.13, PHP 8.6-dev) gc_collect_cycles() stops collecting any
cycle, even a plain PHP one, once the process has called fwrite().
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
final class RowsWriter implements DuckDB\CopyToWriter
{
    public function __construct(public CyclicFormat $format, string $path)
    {
        touch($path);
    }

    public function write(DuckDB\DataChunk $batch): void
    {
        array_push($this->format->rows, ...$batch->toRows(DuckDB\FetchMode::Num));
    }

    public function close(): void
    {
    }

    public function abort(Throwable $reason): void
    {
    }
}

final class CyclicFormat implements DuckDB\CopyToFunction
{
    public ?DuckDB\Connection $connection = null;
    public array $rows = [];

    public function bind(array $columnTypes, array $options): void
    {
    }

    public function open(string $path, array $columnTypes, array $options): DuckDB\CopyToWriter
    {
        return new RowsWriter($this, $path);
    }
}

$target = tempnam(sys_get_temp_dir(), 'duckdb-copy-cycle');
$db = new DuckDB\Database();
foreach (['registered only', 'after a COPY'] as $case) {
    $format = new CyclicFormat();
    $format->connection = $db->connect();
    $format->connection->registerCopyToFunction('cyclic', $format);
    if ($case === 'after a COPY') {
        $format->connection->query("COPY (SELECT range AS i FROM range(3)) TO '$target' (FORMAT cyclic)");
        if ($format->rows !== [[0], [1], [2]]) {
            throw new RuntimeException('The format received ' . json_encode($format->rows));
        }
    }
    $watch = WeakReference::create($format);
    unset($format);
    gc_collect_cycles();
    echo $case, ': ', $watch->get() === null ? 'collected' : 'still alive', "\n";
}
@unlink($target);
?>
--EXPECT--
registered only: collected
after a COPY: collected
