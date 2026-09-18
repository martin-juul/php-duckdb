--TEST--
Errors: connection and statement stay usable after every failure class
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{Database, Exception, ParserException, CatalogException, BinderException,
    ConversionException, ConstraintException, TransactionException};

$conn = (new Database())->connect();
$probe = static fn () => $conn->query("SELECT 'alive' AS s")->fetchRow()['s'];

try { $conn->query('SELEC 1'); } catch (ParserException) { echo "parser caught\n"; }
echo 'after parser: ', $probe(), "\n";

try { $conn->query('SELECT * FROM missing_table'); } catch (CatalogException) { echo "catalog caught\n"; }
echo 'after catalog: ', $probe(), "\n";

try { $conn->query("SELECT 1 + 'a'"); } catch (BinderException) { echo "binder caught\n"; }
echo 'after binder: ', $probe(), "\n";

try { $conn->query("SELECT 'abc'::INTEGER"); } catch (ConversionException) { echo "conversion caught\n"; }
echo 'after conversion: ', $probe(), "\n";

$conn->query('CREATE TABLE pk (i INTEGER PRIMARY KEY)');
$conn->query('INSERT INTO pk VALUES (1)');
try { $conn->query('INSERT INTO pk VALUES (1)'); } catch (ConstraintException) { echo "constraint caught\n"; }
echo 'after constraint: ', $probe(), "\n";

// An error inside an explicit transaction aborts it: every further statement
// fails until the application rolls back, then the connection is clean again.
$conn->beginTransaction();
$conn->query('INSERT INTO pk VALUES (2)');
try { $conn->query('INSERT INTO pk VALUES (1)'); } catch (ConstraintException) { echo "in-tx constraint caught\n"; }
try { $conn->query('SELECT 1'); } catch (TransactionException) { echo "aborted tx: TransactionException\n"; }
$conn->rollBack();
echo 'after rollback: ', $probe(), "\n";
echo 'uncommitted row gone: ', $conn->query('SELECT count(*)::INTEGER AS c FROM pk')->fetchRow()['c'], "\n";

// A stream that fails mid-flight leaves the connection usable
$sql = "SELECT (CASE WHEN range = 150000 THEN 'boom' ELSE range::VARCHAR END)::INTEGER AS v
        FROM range(200000)";
try {
    $result = $conn->queryStreaming($sql);
    while ($result->fetchRow() !== null) {
    }
    echo "stream: NO ERROR (unexpected)\n";
} catch (ConversionException) {
    echo "stream: ConversionException\n";
}
echo 'after stream: ', $probe(), "\n";

// A failed prepare does not poison later prepares
try { $conn->prepare('SELECT * FROM gone'); } catch (CatalogException) { echo "prepare catalog caught\n"; }
echo 'after prepare: ', $conn->prepare('SELECT 6 + 1 AS v')->execute()->fetchRow()['v'], "\n";

// A failed execute does not poison the statement: bind and go again
$stmt = $conn->prepare('SELECT $1::INTEGER AS v');
try { $stmt->execute(); } catch (Exception) { echo "unbound caught\n"; }
$stmt->bindValue(1, 21);
echo 'after bind: ', $stmt->execute()->fetchRow()['v'], "\n";
echo 'params still: ', $stmt->parameterCount(), "\n";
echo "done\n";
?>
--EXPECT--
parser caught
after parser: alive
catalog caught
after catalog: alive
binder caught
after binder: alive
conversion caught
after conversion: alive
constraint caught
after constraint: alive
in-tx constraint caught
aborted tx: TransactionException
after rollback: alive
uncommitted row gone: 1
stream: ConversionException
after stream: alive
prepare catalog caught
after prepare: 7
unbound caught
after bind: 21
params still: 1
done
