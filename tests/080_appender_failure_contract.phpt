--TEST--
Appender failure contract: typed helper conversion is recoverable, native submission or flush requires explicit recovery
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{Appender, Connection, ConstraintException, ConversionException, Database, Integer, Varchar};

function rejects(callable $operation, string $expectedClass, string $label): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $expectedClass) {
            throw new RuntimeException("$label: unexpected " . get_class($error), 0, $error);
        }
        echo $label, "\n";
        return;
    }
    throw new RuntimeException("$label: operation unexpectedly succeeded");
}

function rows(Connection $conn, string $table, array $expected): void {
    $actual = $conn->query("SELECT * FROM $table ORDER BY a")->fetchAll();
    if ($actual !== $expected) {
        throw new RuntimeException("$table changed rows: " . json_encode($actual));
    }
}

function invalidated(Appender $app): void {
    foreach ([fn() => $app->beginRow(), fn() => $app->appendRow([7, 8]), fn() => $app->flush()] as $operation) {
        try {
            $operation();
            throw new RuntimeException('Failed appender accepted further work');
        } catch (DuckDB\Exception $error) {
            if ($error->getMessage() !== 'Appender has failed; call clear() before reusing it') {
                throw new RuntimeException('Failed appender did not become closed', 0, $error);
            }
        }
    }
    // Closing an invalidated handle is still idempotent and must not flush it.
    $app->close();
    $app->close();
}

$conn = (new Database())->connect();
$conn->query('CREATE TABLE whole_rows(a INTEGER, b INTEGER)');
$app = $conn->appender('whole_rows');
$app->appendRow([0, 0]);
$app->flush();

// Same PHP string, different declared source type: only INTEGER fails in the
// helper. No first column or row may be submitted before the whole batch casts.
rejects(fn() => $app->appendRow([new Integer(99), new Integer('bad')]),
    ConversionException::class, 'whole-row helper cast rejected');
$app->flush();
rows($conn, 'whole_rows', [['a' => 0, 'b' => 0]]);
$app->appendRow([new Integer(1), new Integer(2)]);
$app->flush();
rows($conn, 'whole_rows', [['a' => 0, 'b' => 0], ['a' => 1, 'b' => 2]]);
echo "whole-row helper left no partial row and kept appender usable\n";

// VARCHAR converts successfully in the helper; the native Appender API then
// rejects its cast into the table's INTEGER column, after accepting column a.
rejects(fn() => $app->appendRow([new Integer(99), new Varchar('bad')]),
    DuckDB\Exception::class, 'whole-row native submission rejected');
invalidated($app);
rows($conn, 'whole_rows', [['a' => 0, 'b' => 0], ['a' => 1, 'b' => 2]]);
echo "native submission invalidated appender without flushing partial row\n";
$app = $conn->appender('whole_rows');
$app->appendRow([3, 4]);
$app->close();
rows($conn, 'whole_rows', [['a' => 0, 'b' => 0], ['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]]);
echo "replacement appender accepted row\n";

$conn->query('CREATE TABLE partial_rows(a INTEGER, b INTEGER)');
$app = $conn->appender('partial_rows');
$app->beginRow();
$app->append(new Integer(10));
rejects(fn() => $app->append(new Integer('bad')), ConversionException::class,
    'open-row helper cast rejected');
// The failed helper must not consume the affected column or discard column a.
$app->append(new Integer(11));
$app->endRow();
$app->flush();
rows($conn, 'partial_rows', [['a' => 10, 'b' => 11]]);
echo "open-row helper preserved column position and preceding value\n";
$app->beginRow();
$app->append(new Integer(12));
rejects(fn() => $app->append(new Varchar('bad')), DuckDB\Exception::class,
    'open-row native submission rejected');
invalidated($app);
rows($conn, 'partial_rows', [['a' => 10, 'b' => 11]]);
echo "open-row native failure could not resume or flush\n";

// Constraint errors can be delayed until flushing, after every row has been
// submitted successfully. Recovery still requires replacing the appender.
$conn->query('CREATE TABLE constrained_rows(a INTEGER PRIMARY KEY, b INTEGER)');
$app = $conn->appender('constrained_rows');
$app->appendRow([new Integer(5), new Integer(50)]);
$app->flush();
$app->appendRow([new Integer(1), new Integer(10)]);
$app->appendRow([new Integer(1), new Integer(11)]);
rejects(fn() => $app->flush(), ConstraintException::class, 'native constraint flush rejected');
invalidated($app);
rows($conn, 'constrained_rows', [['a' => 5, 'b' => 50]]);
$app = $conn->appender('constrained_rows');
$app->appendRow([new Integer(2), new Integer(20)]);
$app->close();
rows($conn, 'constrained_rows', [['a' => 2, 'b' => 20], ['a' => 5, 'b' => 50]]);
echo "failed flush preserved flushed rows and replacement appender recovered\n";
?>
--EXPECT--
whole-row helper cast rejected
whole-row helper left no partial row and kept appender usable
whole-row native submission rejected
native submission invalidated appender without flushing partial row
replacement appender accepted row
open-row helper cast rejected
open-row helper preserved column position and preceding value
open-row native submission rejected
open-row native failure could not resume or flush
native constraint flush rejected
failed flush preserved flushed rows and replacement appender recovered
