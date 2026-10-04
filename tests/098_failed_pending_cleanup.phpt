--TEST--
Failed pending handles release results before completion without blocking newer queries
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$db = new DuckDB\Database(':memory:', ['threads' => 1]);
$conn = $db->connect();
$observer = $db->connect();
$conn->query('CREATE TABLE started (id INTEGER)');
$heavy = 'SELECT sum(sin(i)) FROM range(10000000) t(i)';
$bad = "SELECT CAST(i::VARCHAR || 'x' AS INTEGER) FROM range(1) t(i)";

foreach (['worker cancel', 'worker error', 'prepared error', 'polling cancel', 'polling error'] as $index => $mode) {
    $old = match ($mode) {
        'worker cancel' => $conn->queryAsync($heavy),
        'worker error' => $conn->queryAsync($bad),
        'prepared error' => $conn->prepare('SELECT CAST(? AS INTEGER)')->executeAsync(['bad']),
        default => $conn->queryPending($mode === 'polling cancel' ? $heavy : $bad),
    };
    if (str_ends_with($mode, 'cancel')) {
        $old->cancel();
    }

    $firstError = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $old->await();
            throw new RuntimeException('Expected the failed handle to keep throwing');
        } catch (DuckDB\Exception $error) {
            $identity = [get_class($error), $error->getMessage(), $error->getErrorType()];
            if ($firstError !== null && $identity !== $firstError) {
                throw new RuntimeException('Repeated await changed the stored error');
            }
            $firstError = $identity;
        }
    }

    // A second connection observes the committed marker, proving the new
    // worker has acquired this connection and entered native execution.
    $next = $conn->queryAsync('INSERT INTO started VALUES (' . $index . '); ' . $heavy);
    $deadline = microtime(true) + 10;
    while ($observer->query('SELECT count(*) FROM started WHERE id = ' . $index)->fetchColumn() !== 1) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('New worker did not publish its start marker');
        }
        usleep(1000);
    }

    unset($old);
    $next->cancel();
    try {
        $next->await();
        throw new RuntimeException('Deleting the old handle waited for the newer query to finish');
    } catch (DuckDB\InterruptedException $error) {
        echo $mode, " cleaned up before newer query\n";
    }
    unset($next);
}
var_dump($conn->query('SELECT 42')->fetchColumn());
?>
--EXPECT--
worker cancel cleaned up before newer query
worker error cleaned up before newer query
prepared error cleaned up before newer query
polling cancel cleaned up before newer query
polling error cleaned up before newer query
int(42)
