--TEST--
Appender clear: explicit discard and recovery without replay or rollback
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t(a INTEGER PRIMARY KEY, b INTEGER)');
$app = $conn->appender('t');
function require_rows(DuckDB\Connection $conn, array $rows): void {
    if ($conn->query('SELECT * FROM t ORDER BY a')->fetchAll() !== $rows) {
        throw new RuntimeException('Unexpected rows after clear');
    }
}
function failed(callable $f, string $class): void {
    try { $f(); } catch (Throwable $e) {
        if ($e instanceof $class) { return; }
        throw $e;
    }
    throw new RuntimeException('Expected failure');
}
$app->appendRow([1, 10]);
$app->flush();
$app->appendRow([2, 20]);
$app->beginRow();
$app->append(3);
$app->clear();
$app->clear();
$app->flush();
require_rows($conn, [['a' => 1, 'b' => 10]]);
$app->appendRow([4, 40]);
$app->flush();
echo "healthy clear discards buffered and partial rows, preserves flushed rows\n";

$app->beginRow();
$app->append(5);
failed(fn() => $app->append('bad'), DuckDB\Exception::class);
failed(fn() => $app->flush(), DuckDB\Exception::class);
$app->clear();
$app->appendRow([6, 60]);
$app->flush();
require_rows($conn, [['a' => 1, 'b' => 10], ['a' => 4, 'b' => 40], ['a' => 6, 'b' => 60]]);
echo "clear recovers native column conversion failure without replay\n";

$app->appendRow([7, 70]);
$app->appendRow([7, 71]);
failed(fn() => $app->flush(), DuckDB\ConstraintException::class);
$app->clear();
$app->appendRow([8, 80]);
$app->flush();
require_rows($conn, [['a' => 1, 'b' => 10], ['a' => 4, 'b' => 40], ['a' => 6, 'b' => 60], ['a' => 8, 'b' => 80]]);
echo "clear recovers constraint flush failure without replay\n";
$app->beginRow();
$app->append(9);
failed(fn() => $app->endRow(), DuckDB\Exception::class);
$app->clear();
$app->flush();
require_rows($conn, [['a' => 1, 'b' => 10], ['a' => 4, 'b' => 40], ['a' => 6, 'b' => 60], ['a' => 8, 'b' => 80]]);
echo "clear discards a row with missing columns\n";
$app->close();
failed(fn() => $app->clear(), DuckDB\Exception::class);
$app->close();
echo "closed appender stays closed\n";

$bad = $conn->appender('t');
$bad->appendRow([1, 100]);
failed(fn() => $bad->close(), DuckDB\ConstraintException::class);
failed(fn() => $bad->clear(), DuckDB\Exception::class);
$bad->close();
echo "failed close remains terminal\n";

// Native destroy calls close internally. Failed handles must clear buffers
// before either explicit close or destruction can reach that path.
foreach ([false, true] as $explicitClose) {
    $discard = $conn->appender('t');
    $discard->appendRow([20, 200]);
    $discard->beginRow();
    failed(fn() => $discard->append('bad'), DuckDB\Exception::class);
    if ($explicitClose) { $discard->close(); }
    unset($discard);
    require_rows($conn, [['a' => 1, 'b' => 10], ['a' => 4, 'b' => 40], ['a' => 6, 'b' => 60], ['a' => 8, 'b' => 80]]);
}
echo "failed close and destruction discard completed buffered rows\n";

// Explicit transactions still own already-flushed changes.
$conn->beginTransaction();
$app = $conn->appender('t');
$app->appendRow([9, 90]);
$app->flush();
$app->appendRow([10, 100]);
$app->clear();
$app->close();
$conn->rollback();
require_rows($conn, [['a' => 1, 'b' => 10], ['a' => 4, 'b' => 40], ['a' => 6, 'b' => 60], ['a' => 8, 'b' => 80]]);
echo "transaction rollback controls flushed rows\n";

$app = $conn->appender('t');
$conn->close();
failed(fn() => $app->clear(), DuckDB\ConnectionException::class);
echo "closed connection rejects clear\n";
?>
--EXPECT--
healthy clear discards buffered and partial rows, preserves flushed rows
clear recovers native column conversion failure without replay
clear recovers constraint flush failure without replay
clear discards a row with missing columns
closed appender stays closed
failed close remains terminal
failed close and destruction discard completed buffered rows
transaction rollback controls flushed rows
closed connection rejects clear
