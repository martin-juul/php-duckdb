--TEST--
Typed integration: atomic conversion, deferred references, async execution and Appender recovery
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Value;
$conn = (new DuckDB\Database())->connect();
$stmt = $conn->prepare('SELECT $a AS a, $b AS b');
$stmt->bindValue('a', 10)->bindValue('b', 20);
try {
    $stmt->execute(['a' => new Value('INTEGER', 100), 'b' => new Value('INTEGER', 'bad')]);
    echo "BAD conversion accepted\n";
} catch (DuckDB\Exception) {
    echo 'atomic statement: ', json_encode($stmt->execute()->fetchRow()), "\n";
}
// Position validation happens before conversion of an invalid value.
try {
    $stmt->execute(['a' => new Value('INTEGER', 'bad'), 9 => new Value('INTEGER', 1)]);
} catch (ValueError) {
    echo "position validated first\n";
}
$original = new Value('INTEGER', 3);
$reference =& $original;
$stmt->bindValue('a', $reference);
$reference = new Value('INTEGER', 99);
echo 'reference detached: ', $stmt->execute()->fetchRow()['a'], "\n";
$stmt->execute(['a' => new Value('DECIMAL(8,2)', '12.345')]);
echo 'persisted decimal: ', $stmt->execute()->fetchRow()['a'], "\n";
$stmt->bindValue('a', 7);
echo 'ordinary override: ', $stmt->execute()->fetchRow()['a'], "\n";
$stmt->clearBindings();
try { $stmt->execute(); } catch (DuckDB\Exception) { echo "clear removes all bindings\n"; }
$stmt->bindValue('a', new Value('INTEGER', 42))->bindValue('b', new Value('INTEGER[]', []));
echo 'async typed: ', json_encode($stmt->executeAsync()->await()->fetchRow()), "\n";
echo 'async repeated: ', json_encode($stmt->executeAsync()->await()->fetchRow()), "\n";
try {
    $stmt->executeAsync(['a' => new Value('INTEGER', 'bad')]);
    echo "BAD pending returned\n";
} catch (DuckDB\Exception) { echo "async conversion rejects before pending\n"; }
echo 'async failure preserves bindings: ', $stmt->execute()->fetchRow()['a'], "\n";
$conn->query('CREATE TABLE recovery(a INTEGER, b INTEGER)');
$app = $conn->appender('recovery');
try {
    $app->appendRow([new Value('INTEGER', 1), new Value('INTEGER', 'bad')]);
} catch (DuckDB\Exception) { echo "row conversion failed\n"; }
$app->appendRow([new Value('INTEGER', 2), new Value('INTEGER', 3)]);
$app->beginRow();
$app->append(new Value('INTEGER', 4));
try { $app->append(new Value('INTEGER', 'bad')); }
catch (DuckDB\Exception) { echo "column conversion failed\n"; }
$app->append(new Value('INTEGER', 5));
$app->endRow();
$app->close();
echo 'recovered rows: ', json_encode($conn->query('SELECT * FROM recovery ORDER BY a')->fetchAll()), "\n";
// The deferred array can contain an unsupported object. Its cycle must still
// be visible to PHP's collector even when execution never reaches conversion.
$cyclicStmt = $conn->prepare('SELECT ?');
$weak = WeakReference::create($cyclicStmt);
$outer = [new Value('INTEGER', 1), $cyclicStmt];
$cyclicStmt->bindValue(1, $outer);
unset($cyclicStmt, $outer);
gc_collect_cycles();
echo 'deferred cycle collected: ', $weak->get() === null ? 'yes' : 'no', "\n";
// PostgreSQL-style aliases accepted by DuckDB remain valid declarations.
foreach (['NATIONAL CHARACTER VARYING(3)', 'NATIONAL CHAR(3)',
          'CHAR VARYING(3)', 'NCHAR VARYING(3)', 'INTERVAL YEAR TO MONTH',
          'INTERVAL DAY TO SECOND', 'INTERVAL YEAR'] as $alias) {
    $value = new Value($alias, null);
    if (!$conn->execute('SELECT ? IS NULL AS ok', [$value])->fetchRow()['ok']) {
        throw new RuntimeException("Alias failed: $alias");
    }
}
echo "multiword aliases accepted\n";
$conn->query('CREATE TYPE café AS INTEGER');
$conn->query('CREATE TYPE foo$bar AS INTEGER');
foreach (['café', 'main.café', '"café"', 'main."café"', 'foo$bar'] as $type) {
    if ($conn->execute('SELECT ? AS v', [new Value($type, 8)])->fetchRow()['v'] !== 8) {
        throw new RuntimeException("Catalog identifier failed: $type");
    }
}
echo "catalog identifiers accepted\n";
$numericFields = new Value('STRUCT("1" INTEGER, "-1" VARCHAR)', [1 => 7, -1 => 'x']);
echo 'numeric struct fields: ', json_encode(
    $conn->execute('SELECT ? AS v', [$numericFields])->fetchRow()['v']), "\n";
try { new Value('INTEGER' . str_repeat('[]', 200), null); }
catch (ValueError) { echo "array suffix depth rejected\n"; }
class TypedSnapshotDate extends DateTime {
    public static int $cloneHooks = 0;
    public function __clone() { self::$cloneHooks++; }
}
$date = new TypedSnapshotDate('2020-01-01', new DateTimeZone('UTC'));
$snapshot = new Value('DATE', $date);
$date->modify('+1 year');
echo 'temporal subclass snapshot: ',
    $conn->execute('SELECT ?::VARCHAR AS v', [$snapshot])->fetchRow()['v'],
    ', hooks=', TypedSnapshotDate::$cloneHooks, "\n";
foreach ([DateTime::class, DateTimeImmutable::class] as $temporalClass) {
    $uninitialized = (new ReflectionClass($temporalClass))->newInstanceWithoutConstructor();
    try { new Value('DATE', $uninitialized); echo "BAD uninitialized temporal\n"; }
    catch (TypeError) { echo 'uninitialized ', $temporalClass, " rejected\n"; }
    try { $conn->execute('SELECT ?', [$uninitialized]); echo "BAD ordinary uninitialized temporal\n"; }
    catch (TypeError) { echo 'ordinary uninitialized ', $temporalClass, " rejected\n"; }
}
$conn->query("CREATE TYPE changing_enum AS ENUM('a','b')");
$catalogStmt = $conn->prepare('SELECT ?::VARCHAR AS v');
$catalogStmt->bindValue(1, new Value('changing_enum', 'a'));
$catalogStmt->execute();
$conn->query('DROP TYPE changing_enum');
$conn->query("CREATE TYPE changing_enum AS ENUM('c','a')");
echo 'recreated enum: ', $catalogStmt->execute()->fetchRow()['v'], "\n";
$conn->query('DROP TYPE changing_enum');
$conn->query("CREATE TYPE changing_enum AS ENUM('c','d')");
try { $catalogStmt->execute(); }
catch (DuckDB\Exception) { echo "removed enum label rejected\n"; }
$catalogStmt->bindValue(1, new Value('changing_enum', 'd'));
echo 'recreated enum recovery: ', $catalogStmt->execute()->fetchRow()['v'], "\n";
$file = tempnam(sys_get_temp_dir(), 'duckdb-typed-readonly-');
unlink($file);
$fileDb = new DuckDB\Database($file);
$fileConn = $fileDb->connect();
$fileConn->query('CREATE TABLE t(a INTEGER)');
$fileConn->close();
unset($fileConn, $fileDb);
try {
    $fileDb = new DuckDB\Database($file, ['access_mode' => 'READ_ONLY']);
    $fileConn = $fileDb->connect();
    echo 'readonly conversion: ', $fileConn->execute('SELECT ? AS v',
        [new Value('INTEGER', '3')])->fetchRow()['v'], "\n";
    $fileConn->close();
    unset($fileConn, $fileDb);
} finally { unlink($file); }
$closedStmt = $conn->prepare('SELECT ?');
$closedStmt->bindValue(1, new Value('INTEGER', 1));
$conn->close();
try { $closedStmt->executeAsync(); }
catch (DuckDB\Exception) { echo "closed connection rejected\n"; }
?>
--EXPECT--
atomic statement: {"a":10,"b":20}
position validated first
reference detached: 3
persisted decimal: 12.35
ordinary override: 7
clear removes all bindings
async typed: {"a":42,"b":[]}
async repeated: {"a":42,"b":[]}
async conversion rejects before pending
async failure preserves bindings: 42
row conversion failed
column conversion failed
recovered rows: [{"a":2,"b":3},{"a":4,"b":5}]
deferred cycle collected: yes
multiword aliases accepted
catalog identifiers accepted
numeric struct fields: {"1":7,"-1":"x"}
array suffix depth rejected
temporal subclass snapshot: 2020-01-01, hooks=0
uninitialized DateTime rejected
ordinary uninitialized DateTime rejected
uninitialized DateTimeImmutable rejected
ordinary uninitialized DateTimeImmutable rejected
recreated enum: a
removed enum label rejected
recreated enum recovery: d
readonly conversion: 3
closed connection rejected
