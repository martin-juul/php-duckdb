<?php
// Build a small document store and inspect the API used by a generic SQL client.

use DuckDB\{Database, FetchMode};

require __DIR__ . '/bootstrap.php';

printf("DuckDB %s\n", DuckDB\version());
$connection = (new Database())->connect();
$connection->query('CREATE TABLE documents (id INTEGER PRIMARY KEY, title VARCHAR, body BLOB)');
$insert = $connection->prepare('INSERT INTO documents VALUES ($id, $title, $body)');
printf("statement: %s, parameters: %d\n", $insert->statementType(), $insert->parameterCount());
for ($index = 1; $index <= $insert->parameterCount(); $index++) {
    printf("  %s: %s\n", $insert->parameterName($index), $insert->parameterType($index));
}

$insert->bindValue('id', 1)->bindValue('title', 'Invoice')->bindBlob('body', "PDF\0binary");
$change = $insert->execute();
printf("%s changed %d row(s)\n", $change->statementType(), $change->rowsChanged());
$insert->clearBindings();
$insert->bindValue('id', 2)->bindValue('title', 'Receipt')->bindBlob('body', "PNG\0binary");
$insert->execute();

// Metadata is available before execution: useful for building table headings.
$sql = 'SELECT id, title, octet_length(body) AS bytes FROM documents WHERE id >= $minimum ORDER BY id';
$select = $connection->prepare($sql);
// Table extraction binds the query, so use concrete values rather than parameters.
printf("tables: %s\n", implode(', ', $connection->getTableNames('SELECT * FROM documents WHERE id >= 1')));
for ($index = 0; $index < $select->columnCount(); $index++) {
    printf("  %s: %s\n", $select->columnName($index), $select->columnType($index));
}

$result = $select->execute(['minimum' => 1]);
printf("buffered rows: %d, columns: %d\n", $result->rowCount(), $result->columnCount());
foreach ($result->columns() as $index => $column) {
    printf("  %s (%s)\n", $result->columnName($index), $result->columnType($index));
}
print_r($result->fetchRow(FetchMode::Assoc));
print_r($result->fetchAll(FetchMode::Num));
print_r($select->execute(['minimum' => 2])->fetchRow(FetchMode::Both));
printf("document count: %d\n", $connection->query('SELECT count(*) FROM documents')->fetchColumn());

// Iterators are forward-only. Rewind once to initialize; never rewind after next().
$iterator = $select->executeStreaming(['minimum' => 1])->getIterator();
$iterator->rewind();
while ($iterator->valid()) {
    printf("row %d: %s\n", $iterator->key(), $iterator->current()['title']);
    $iterator->next();
}

// Clear abandons unflushed and partially assembled rows, not committed data.
$appender = $connection->appender('documents');
$appender->appendRow([3, 'Draft', new DuckDB\Blob('discard me')]);
$appender->clear();
$appender->appendRow([3, 'Signed copy', new DuckDB\Blob('keep me')]);
$appender->flush();
try {
    $appender->beginRow();
    $appender->append('invalid document id');
} catch (DuckDB\Exception $error) {
    // Native conversion failed: discard the partial row before reusing this appender.
    $appender->clear();
    printf("Rejected document: %s\n", $error->getErrorType()?->name ?? 'unknown');
}

$appender->appendRow([4, 'Corrected document', new DuckDB\Blob('accepted')]);
$appender->close();
if ($connection->query('SELECT title FROM documents WHERE id = 3')->fetchColumn() !== 'Signed copy') {
    throw new RuntimeException('Appender recovery did not preserve the accepted document');
}

$connection->close();
printf("connection closed: %s\n", $connection->isClosed() ? 'yes' : 'no');
