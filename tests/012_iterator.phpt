--TEST--
Result: iterator protocol (IteratorAggregate, forward-only guarantees)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// foreach over a Result
$result = $conn->query('SELECT i::BIGINT AS i FROM range(3) t(i)');
foreach ($result as $key => $row) {
    echo "$key: ", $row['i'], "\n";
}

// Only one iterator per result
$result = $conn->query('SELECT 1 AS a');
$iterator = $result->getIterator();
try {
    $result->getIterator();
} catch (DuckDB\Exception $e) {
    echo 'second iterator: ', $e->getMessage(), "\n";
}

// Iterators cannot be rewound once advanced
foreach ($iterator as $row) {
}
try {
    $iterator->rewind();
} catch (DuckDB\Exception $e) {
    echo 'rewind: ', $e->getMessage(), "\n";
}

// Manual protocol
$result = $conn->query('SELECT i::BIGINT AS i FROM range(2) t(i)');
$it = $result->getIterator();
$it->rewind();
var_dump($it->valid());
var_dump($it->key());
var_dump($it->current());
$it->next();
$it->next();
var_dump($it->valid());
?>
--EXPECT--
0: 0
1: 1
2: 2
second iterator: DuckDB\Result is forward-only: an iterator was already created for this result
rewind: Cannot rewind a DuckDB\ResultIterator (results are forward-only)
bool(true)
int(0)
array(1) {
  ["i"]=>
  int(0)
}
bool(false)
