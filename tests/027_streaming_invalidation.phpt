--TEST--
Result: stale streaming results fail loudly (one stream per connection)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// A newer execution on the same connection invalidates an open stream;
// the stale stream throws instead of silently truncating.
$stale = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(100000) t(i)');
var_dump($stale->fetchRow());

$active = $conn->queryStreaming('SELECT (i + 10)::BIGINT AS i FROM range(3) t(i)');
var_dump($active->fetchRow());

try {
    while ($stale->fetchRow() !== null) {
    }
    echo "NO ERROR\n";
} catch (DuckDB\Exception $e) {
    echo 'stale stream detected', "\n";
}

// The active stream is unaffected
var_dump($active->fetchAll());

// Materialized results are unaffected by interleaving
$a = $conn->query('SELECT i::BIGINT AS i FROM range(2) t(i)');
$b = $conn->query('SELECT (i + 10)::BIGINT AS i FROM range(2) t(i)');
echo $a->fetchRow()['i'], $b->fetchRow()['i'], $a->fetchRow()['i'], "\n";

// Creating an appender also invalidates open streams
$conn->query('CREATE TABLE t (i INT)');
$stream = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(100000) t(i)');
$appender = $conn->appender('t');
$appender->appendRow([1]);
$appender->close();
try {
    while ($stream->fetchRow() !== null) {
    }
    echo "NO ERROR\n";
} catch (DuckDB\Exception $e) {
    echo 'stream invalidated by appender', "\n";
}
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());
?>
--EXPECT--
array(1) {
  ["i"]=>
  int(0)
}
array(1) {
  ["i"]=>
  int(10)
}
stale stream detected
array(2) {
  [0]=>
  array(1) {
    ["i"]=>
    int(11)
  }
  [1]=>
  array(1) {
    ["i"]=>
    int(12)
  }
}
0101
stream invalidated by appender
array(1) {
  ["c"]=>
  int(1)
}
