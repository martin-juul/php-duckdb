--TEST--
Result: an open stream refuses a newer statement with ResourceInUse and stays readable
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function refused(callable $operation): void {
    try {
        $operation();
        echo "NOT REFUSED\n";
    } catch (DuckDB\Exception $e) {
        echo $e->getErrorType()->name, ': ', $e->getMessage(), "\n";
    }
}

$conn = (new DuckDB\Database())->connect();

// DuckDB 2.0 allows one open stream per connection: a newer statement is
// refused, and the open stream keeps every row.
$open = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(100000) t(i)');
var_dump($open->fetchRow());

refused(fn() => $conn->queryStreaming('SELECT (i + 10)::BIGINT AS i FROM range(3) t(i)'));

$count = 1;
while ($open->fetchRow() !== null) {
    $count++;
}
echo "open stream rows: $count\n";

// Once the stream has drained, the connection runs statements again.
$active = $conn->queryStreaming('SELECT (i + 10)::BIGINT AS i FROM range(3) t(i)');
var_dump($active->fetchRow());
var_dump($active->fetchAll());

// Materialized results never hold the connection, so they interleave.
$a = $conn->query('SELECT i::BIGINT AS i FROM range(2) t(i)');
$b = $conn->query('SELECT (i + 10)::BIGINT AS i FROM range(2) t(i)');
echo $a->fetchRow()['i'], $b->fetchRow()['i'], $a->fetchRow()['i'], "\n";

// An appender can be created while a stream is open, but it refuses to
// write rows until the stream is gone, and keeps them buffered.
$conn->query('CREATE TABLE t (i INT)');
$stream = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(100000) t(i)');
$appender = $conn->appender('t');
refused(fn() => $appender->appendRow([1]));
refused(fn() => $appender->close());
echo 'stream rows: ', count($stream->fetchAll()), "\n";

$appender->appendRow([1]);
$appender->close();
var_dump($conn->query('SELECT count(*)::INT AS c FROM t')->fetchRow());

// Releasing an undrained stream also frees the connection.
$stream = $conn->queryStreaming('SELECT i::BIGINT AS i FROM range(100000) t(i)');
var_dump($stream->fetchRow()['i']);
unset($stream);
var_dump($conn->query('SELECT 42 AS answer')->fetchRow());
?>
--EXPECT--
array(1) {
  ["i"]=>
  int(0)
}
ResourceInUse: Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
open stream rows: 100000
array(1) {
  ["i"]=>
  int(10)
}
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
ResourceInUse: Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
ResourceInUse: Resource In Use Error: connection has an open result; drain or destroy it before starting a new query
stream rows: 100000
array(1) {
  ["c"]=>
  int(1)
}
int(0)
array(1) {
  ["answer"]=>
  int(42)
}
