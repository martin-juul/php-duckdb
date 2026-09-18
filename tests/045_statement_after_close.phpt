--TEST--
Lifecycle: statements and binding reject use after Connection::close()
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$stmt = $conn->prepare('SELECT ? AS v');
$conn->close();

foreach (['bindValue' => fn() => $stmt->bindValue(1, 42),
          'bindBlob' => fn() => $stmt->bindBlob(1, 'x'),
          'clearBindings' => fn() => $stmt->clearBindings(),
          'execute' => fn() => $stmt->execute(),
          'executeStreaming' => fn() => $stmt->executeStreaming(),
          'executeAsync' => fn() => $stmt->executeAsync()] as $label => $fn) {
    try {
        $fn();
        echo "$label: NOT REJECTED\n";
    } catch (DuckDB\ConnectionException $e) {
        echo "$label: ConnectionException\n";
    }
}

// pure metadata is still readable on a closed connection
$conn2 = (new DuckDB\Database())->connect();
$stmt2 = $conn2->prepare('SELECT $myparam::INTEGER AS v');
$conn2->close();
echo "parameterCount: ", $stmt2->parameterCount(), "\n";
echo "parameterName: ", $stmt2->parameterName(1), "\n";
echo "done\n";
?>
--EXPECT--
bindValue: ConnectionException
bindBlob: ConnectionException
clearBindings: ConnectionException
execute: ConnectionException
executeStreaming: ConnectionException
executeAsync: ConnectionException
parameterCount: 1
parameterName: myparam
done
