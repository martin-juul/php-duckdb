<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\Database;
use DuckDB\DataChunk;
use DuckDB\SelectionVector;

function checkSelection(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$conn = $db->connect();
$conn->query('CREATE TABLE readings(sensor VARCHAR, celsius DOUBLE, tags VARCHAR[])');
$conn->query('CREATE TABLE quarantine(sensor VARCHAR, celsius DOUBLE, tags VARCHAR[])');

// Readings arrive as columnar batches. Each batch is validated in PHP, and the
// valid and rejected rows are routed without rebuilding any row.
$source = $db->connect()->queryStreaming(
    "SELECT 'sensor-' || (i % 7) AS sensor,
            CASE WHEN i % 50 = 0 THEN NULL WHEN i % 33 = 0 THEN 999.0 ELSE 15 + (i % 20) * 0.5 END AS celsius,
            CASE WHEN i % 4 = 0 THEN ['calibrated'] ELSE [] END AS tags
     FROM range(5000) t(i)"
);
$writer = $db->connect();
$valid = $writer->appender('readings');
$rejected = $writer->appender('quarantine');
$batches = 0;
while (($arrow = $source->fetchArrowChunk()) !== null) {
    $batch = $writer->dataChunkFromArrow($arrow);
    $celsius = $batch->vector(1)->toArray();
    $good = array_keys(array_filter($celsius, fn(?float $value) => $value !== null && $value < 60.0));
    $bad = array_keys(array_diff_key($celsius, array_flip($good)));

    // Each batch holds at most vectorSize() rows, so each selection fits a chunk.
    $valid->appendChunk($batch->select($good));
    $rejected->appendChunk($batch->select($bad));
    ++$batches;
}
$valid->close();
$rejected->close();

$counts = $writer->query(
    'SELECT (SELECT count(*) FROM readings) AS valid, (SELECT count(*) FROM quarantine) AS rejected'
)->fetchRow();
checkSelection($counts['valid'] + $counts['rejected'] === 5000, 'Readings were lost while routing');

// One selection reorders several columns. Here the hottest five readings of
// a batch are picked once and applied to the sensor and value columns.
$sample = $writer->dataChunkFromArrow(
    $writer->queryStreaming('SELECT sensor, celsius FROM readings ORDER BY sensor, celsius')->fetchArrowChunk()
);
$values = $sample->vector(1)->toArray();
arsort($values);
$hottest = new SelectionVector(array_slice(array_keys($values), 0, 5));
$sensors = $sample->vector(0)->select($hottest);
$top = $sample->vector(1)->select($hottest);
checkSelection(count($hottest) === 5 && $top->get(0) === max($values), 'Hottest reading was not selected first');

// Gather rows into an existing staging vector: the first slot keeps a sentinel
// and the selected readings fill the rest.
$staging = $conn->createVector('DOUBLE', 6);
$staging->set($conn, 0, -273.15);
$staging->copySelected($top, [0, 1, 2, 3, 4], 1);
$report = DataChunk::fromVectors(['celsius' => $staging], 6)->toRows();
checkSelection($report[0]['celsius'] === -273.15 && $report[1]['celsius'] === $top->get(0), 'Gather changed the report');

echo "Routed {$counts['valid']} valid and {$counts['rejected']} rejected readings from {$batches} batches; "
    . 'hottest sensor ' . $sensors->get(0) . "\n";
