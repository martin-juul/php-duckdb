<?php
require __DIR__ . '/bootstrap.php';

use DuckDB\Database;
use DuckDB\FetchMode;

function checkArrow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$source = $db->connect();
$destination = $db->connect();
$destination->query('CREATE TABLE events(id INTEGER, amount DECIMAL(10,2), tags VARCHAR[])');
$appender = $destination->appender('events');

// Streaming keeps memory bounded. The consumer owns imported chunks independently
// of the next fetch; appending an Arrow batch moves its array ownership.
$result = $source->queryStreaming("SELECT i::INTEGER AS id, (i / 100)::DECIMAL(10,2) AS amount, ['arrow', NULL] AS tags FROM range(4097) t(i)");
$schema = $result->arrowSchema();
checkArrow(array_column($schema->toArray()['children'], 'name') === ['id', 'amount', 'tags'], 'Unexpected source schema');
$total = 0;
$batches = 0;
while (($batch = $result->fetchArrowChunk()) !== null) {
    $count = $batch->rowCount();
    checkArrow(!$batch->isConsumed(), 'New batch is already consumed');
    checkArrow($batch->schema()->toArray() === $schema->toArray(), 'Batch schema changed');
    $native = $destination->dataChunkFromArrow($batch);
    checkArrow($batch->isConsumed(), 'Import must consume the Arrow batch');
    checkArrow($native->rowCount() === $count && $native->columnCount() === 3, 'Chunk dimensions changed');
    checkArrow(array_column($native->columns(), 'name') === ['id', 'amount', 'tags'], 'Native columns changed');

    // PHP decoding is optional. Reading these rows does not consume native data.
    $rows = $native->toRows();
    $numeric = $native->toRows(FetchMode::Num);
    $both = $native->toRows(FetchMode::Both);
    checkArrow($rows[0]['id'] === $total && $numeric[0][0] === $total && $both[0]['id'] === $both[0][0], 'Decoded row mismatch');
    checkArrow($native->arrowSchema($destination)->toArray() === $schema->toArray(), 'Native export schema changed');

    // Alternate reusable native append and Arrow append. This illustrates both
    // ingestion APIs without decoding and rebuilding individual PHP rows.
    if ($batches % 2 === 0) {
        $appender->appendChunk($native);
    } else {
        $outgoing = $native->toArrow($destination);
        checkArrow($outgoing->rowCount() === $count, 'Native export changed row count');
        $appender->appendArrow($outgoing);
        checkArrow($outgoing->isConsumed(), 'Arrow append must consume the batch');
    }
    $total += $count;
    ++$batches;
}
$appender->close();

$summary = $destination->query('SELECT count(*) AS rows, min(id) AS first_id, max(id) AS last_id, sum(amount) AS total FROM events')->fetchRow();
checkArrow($total === 4097 && $summary['rows'] === 4097 && $summary['first_id'] === 0 && $summary['last_id'] === 4096, 'Pipeline lost rows');
checkArrow($summary['total'] === '83906.56', 'Pipeline changed decimal values');
echo "Arrow pipeline ingested {$total} rows in {$batches} batches; decimal total {$summary['total']}\n";
