--TEST--
Hardening: internal final classes refuse constructor bypass (reflection + unserialize)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
// Every DuckDB class is final and internal, so the engine itself refuses to
// create an instance without running its constructor: reflection throws and
// unserialize fails. Lock that invariant in — if a class ever loses `final`
// or gains an unserialize path, the internal handle could be null and method
// calls would depend on duckdb_initialized_guard to fail loudly.
$classes = [
    'Database'       => DuckDB\Database::class,
    'Connection'     => DuckDB\Connection::class,
    'Statement'      => DuckDB\Statement::class,
    'Result'         => DuckDB\Result::class,
    'ResultIterator' => DuckDB\ResultIterator::class,
    'PendingQuery'   => DuckDB\PendingQuery::class,
    'Appender'       => DuckDB\Appender::class,
];
foreach ($classes as $label => $class) {
    $rc = new ReflectionClass($class);
    echo $label, ': ', $rc->isFinal() ? 'final' : 'NOT FINAL', ', ';
    try {
        $rc->newInstanceWithoutConstructor();
        echo "reflection NOT REFUSED, ";
    } catch (ReflectionException $e) {
        echo "reflection refused, ";
    }
    $payload = 'O:' . strlen($class) . ':"' . $class . '":0:{}';
    try {
        $obj = @unserialize($payload);
        echo $obj === false ? "unserialize refused\n" : "unserialize NOT REFUSED\n";
    } catch (Throwable $e) {
        echo "unserialize refused\n";
    }
}
echo "done\n";
?>
--EXPECT--
Database: final, reflection refused, unserialize refused
Connection: final, reflection refused, unserialize refused
Statement: final, reflection refused, unserialize refused
Result: final, reflection refused, unserialize refused
ResultIterator: final, reflection refused, unserialize refused
PendingQuery: final, reflection refused, unserialize refused
Appender: final, reflection refused, unserialize refused
done
