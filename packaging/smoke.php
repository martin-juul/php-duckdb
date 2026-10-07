<?php

// Run with the installed package's normal PHP configuration, not a build-tree
// extension or LD_LIBRARY_PATH override. Exercise the engine patches as well
// as the PHP module so accidentally loading a system engine fails this check.
$pins = json_decode(file_get_contents(__DIR__ . '/duckdb/source.json'), true, 512, JSON_THROW_ON_ERROR);
if (ltrim(DuckDB\version(), 'v') !== $pins['version']) {
    throw new RuntimeException('Installed package loaded a different DuckDB engine version');
}

$db = new DuckDB\Database();
$conn = $db->connect();
$sql = "SELECT '340282366920938463463374607431768211455'::UHUGEINT AS n,
    '00101'::BIT AS bits, '12:34:56+02'::TIMETZ AS clock";
$expected = $conn->query($sql)->fetchAll();
$native = $conn->dataChunkFromArrow($conn->query($sql)->fetchArrowChunk());
if ($native->toRows() !== $expected) {
    throw new RuntimeException('Installed package lost values during Arrow conversion');
}

// Vector APIs resolve lazily; call them so an engine without them fails here.
$clock = $conn->createVector('TIMETZ[]', 1);
$clock->set($conn, 0, ['12:34:56+02']);
$vectorRows = DuckDB\DataChunk::fromVectors(['clock' => $clock], 1)->toRows();
if ($vectorRows !== [['clock' => [$expected[0]['clock']]]]) {
    throw new RuntimeException('Installed package lost values in a native vector');
}
$selectedRows = $native->select(new DuckDB\SelectionVector([0, 0]))->toRows();
if ($selectedRows !== [$expected[0], $expected[0]]) {
    throw new RuntimeException('Installed package lost values in a selected chunk');
}

$sql = "SELECT 'POINT (1 2)'::GEOMETRY('OGC:CRS84') AS point";
$native = $conn->dataChunkFromArrow($conn->query($sql)->fetchArrowChunk());
$field = $native->arrowSchema($conn)->toArray()['children'][0];
$metadata = array_column($field['metadata'], 'value', 'key');
$description = json_decode($metadata['ARROW:extension:metadata'], true, 512, JSON_THROW_ON_ERROR);
if (($description['crs']['id']['authority'] ?? null) !== 'OGC' ||
    ($description['crs']['id']['code'] ?? null) !== 'CRS84') {
    throw new RuntimeException('Installed package lost geometry CRS metadata');
}

$conn->query("CREATE TABLE geometry_smoke(point GEOMETRY('OGC:CRS84'))");
$conn->beginTransaction();
$appender = $conn->appender('geometry_smoke');
$appender->appendChunk($native);
$appender->close();
try {
    $conn->query("SELECT 'text'::VARIANT AS v")->fetchArrowChunk();
    throw new RuntimeException('Installed package unexpectedly accepted VARIANT export');
} catch (DuckDB\Exception $error) {
    if (!str_contains($error->getMessage(), 'VARIANT')) {
        throw $error;
    }
}
if ($conn->query('SELECT count(*) AS n FROM geometry_smoke')->fetchRow()['n'] !== 1) {
    throw new RuntimeException('Installed package lost the caller transaction');
}
$conn->rollback();
if ($conn->query('SELECT count(*) AS n FROM geometry_smoke')->fetchRow()['n'] !== 0) {
    throw new RuntimeException('Installed package committed the caller transaction');
}

// PHP COPY formats need the patched binder: other connections must not see
// the format, and parallel COPY options must be rejected while binding.
$copy = new class implements DuckDB\CopyToFunction, DuckDB\CopyToWriter {
    public array $rows = [];

    public function bind(array $columnTypes, array $options): void
    {
    }

    public function open(string $path, array $columnTypes, array $options): DuckDB\CopyToWriter
    {
        touch($path);
        return $this;
    }

    public function write(DuckDB\DataChunk $batch): void
    {
        array_push($this->rows, ...$batch->toRows(DuckDB\FetchMode::Num));
    }

    public function close(): void
    {
    }

    public function abort(Throwable $reason): void
    {
    }
};
$target = tempnam(sys_get_temp_dir(), 'duckdb-smoke');
$conn->registerCopyToFunction('smoke_php', $copy);
$conn->query("COPY (SELECT range AS i FROM range(3)) TO '$target' (FORMAT smoke_php)");
if ($copy->rows !== [[0], [1], [2]]) {
    throw new RuntimeException('Installed package lost rows in a PHP COPY format');
}
try {
    $conn->query("COPY (SELECT 1 AS p, 2 AS v) TO '$target' (FORMAT smoke_php, PARTITION_BY (p))");
    throw new RuntimeException('Installed package accepted PARTITION_BY for a PHP COPY format');
} catch (DuckDB\BinderException $error) {
}
try {
    $db->connect()->query("COPY (SELECT 1) TO '$target' (FORMAT smoke_php)");
    throw new RuntimeException('Installed package exposed a PHP COPY format to another connection');
} catch (DuckDB\CatalogException $error) {
}
@unlink($target);

printf("smoke OK: duckdb ext %s, patched libduckdb %s, PHP %s\n",
    phpversion('duckdb'), DuckDB\version(), PHP_VERSION);
