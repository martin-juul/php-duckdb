--TEST--
Hardening: (un)serialization of driver objects is denied (no dangling internals)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// unserialize() must refuse to create objects whose C++ internals were
// never initialized (would segfault on the next method call)
$payloads = [
    'Database' => 'O:15:"DuckDB\\Database":0:{}',
    'Connection' => 'O:17:"DuckDB\\Connection":0:{}',
    'Statement' => 'O:16:"DuckDB\\Statement":0:{}',
    'Result' => 'O:13:"DuckDB\\Result":0:{}',
    'ResultIterator' => 'O:21:"DuckDB\\ResultIterator":0:{}',
    'PendingQuery' => 'O:19:"DuckDB\\PendingQuery":0:{}',
    'Appender' => 'O:15:"DuckDB\\Appender":0:{}',
];
foreach ($payloads as $label => $payload) {
    $obj = @unserialize($payload);
    echo "$label: ", $obj === false ? "refused" : "LEAKED", "\n";
}

// serialize() of a live object is denied with a catchable exception
try {
    serialize($conn);
    echo "serialize: LEAKED\n";
} catch (\Exception $e) {
    echo "serialize: ", $e->getMessage(), "\n";
}

// reflection instantiation without constructor is blocked by the engine
try {
    (new ReflectionClass(DuckDB\Connection::class))->newInstanceWithoutConstructor();
    echo "reflection: LEAKED\n";
} catch (\ReflectionException $e) {
    echo "reflection: refused\n";
}

// cloning is blocked
try {
    clone $conn;
    echo "clone: LEAKED\n";
} catch (\Error $e) {
    echo "clone: refused\n";
}

echo "done\n";
?>
--EXPECT--
Database: refused
Connection: refused
Statement: refused
Result: refused
ResultIterator: refused
PendingQuery: refused
Appender: refused
serialize: Serialization of 'DuckDB\Connection' is not allowed
reflection: refused
clone: refused
done
