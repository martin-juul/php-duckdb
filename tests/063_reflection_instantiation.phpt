--TEST--
Hardening: objects created via ReflectionClass::newInstanceWithoutConstructor fail with Error
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
// Bypassing the constructor leaves the internal C++ handle null; every
// method must fail with a hard Error instead of dereferencing it.
$cases = [
    'Database::connect'    => [DuckDB\Database::class, 'connect'],
    'Connection::query'    => [DuckDB\Connection::class, 'query', 'SELECT 1'],
    'Connection::close'    => [DuckDB\Connection::class, 'close'],
    'Connection::isClosed' => [DuckDB\Connection::class, 'isClosed'],
    'Statement::execute'   => [DuckDB\Statement::class, 'execute'],
    'Result::fetchRow'     => [DuckDB\Result::class, 'fetchRow'],
    'Result::rowCount'     => [DuckDB\Result::class, 'rowCount'],
    'ResultIterator::next' => [DuckDB\ResultIterator::class, 'next'],
    'PendingQuery::await'  => [DuckDB\PendingQuery::class, 'await'],
    'PendingQuery::cancel' => [DuckDB\PendingQuery::class, 'cancel'],
    'Appender::flush'      => [DuckDB\Appender::class, 'flush'],
];
foreach ($cases as $label => $case) {
    $class  = $case[0];
    $method = $case[1];
    $args   = array_slice($case, 2);
    $obj = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    try {
        $obj->$method(...$args);
        echo "$label: NOT GUARDED\n";
    } catch (\Error $e) {
        echo "$label: Error\n";
    }
}
echo "done\n";
?>
--EXPECT--
Database::connect: Error
Connection::query: Error
Connection::close: Error
Connection::isClosed: Error
Statement::execute: Error
Result::fetchRow: Error
Result::rowCount: Error
ResultIterator::next: Error
PendingQuery::await: Error
PendingQuery::cancel: Error
Appender::flush: Error
done
