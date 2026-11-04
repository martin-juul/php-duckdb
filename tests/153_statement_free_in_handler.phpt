--TEST--
COPY TO: freeing a prepared statement inside a handler does not deadlock
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/copy.inc';

// DuckDB 2.0 takes the client-context lock when it destroys a prepared
// statement, and a running COPY holds that lock while its handler runs, so
// the destroy waits until the handler has returned.
foreach ([1, 4] as $threads) {
    $dir = copy_scratch();
    $conn = (new DuckDB\Database(':memory:', ['threads' => $threads]))->connect();
    $format = new LinesFormat();
    $conn->registerCopyToFunction('lines', $format);

    $statement = $conn->prepare('SELECT 41 + 1 AS answer');
    $format->hooks['write'] = function () use (&$statement) {
        $statement = null;
    };
    $conn->query("COPY (SELECT range AS i FROM range(10)) TO '$dir/x.lines' (FORMAT lines)");
    check($statement === null, 'The handler released the statement');
    check(count(copy_lines("$dir/x.lines")) === 10, 'The COPY wrote every row');

    $again = $conn->prepare('SELECT 41 + 1 AS answer');
    check($again->execute()->fetchColumn() === 42, 'The connection prepares and runs statements afterwards');
    echo "threads=$threads ok\n";
}
?>
--EXPECT--
threads=1 ok
threads=4 ok
