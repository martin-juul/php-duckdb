--TEST--
Typed values: canonical types, immutable snapshots, declaration safety and serialization
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Value;
// These declarations and snapshots need no connection.
$number = 1;
$items = [&$number];
$list = new Value('INTEGER[]', $items);
$number = 9;
$items[] = 10;
$date = new DateTime('2026-01-01 12:00:00.123456+02:00');
$timestamp = new Value('TIMESTAMP', $date);
$date->modify('+1 day');
$nested = new Value('STRUCT(items INTEGER[], price DECIMAL(18,2))', [
    'price' => new Value('DECIMAL(18,2)', '1.235'),
    'items' => $list,
]);
echo (new Value(' decimal ( 18 , 2 ) ', '1'))->getType(), "\n";
$interval = new DuckDB\Interval(1, 2, 3);
$intervalValue = new Value('INTERVAL', $interval);
$interval->__construct(9, 9, 9);
$conn = (new DuckDB\Database())->connect();
if ($conn->execute("SELECT ? = INTERVAL '1 month 2 days 3 microseconds' AS ok", [$intervalValue])->fetchRow()['ok'] !== true) {
    throw new RuntimeException('Interval snapshot changed');
}
try { $list->__construct('VARCHAR', 'changed'); echo "mutation accepted\n"; }
catch (Error $e) { echo "mutation rejected\n"; }
$row = $conn->execute('SELECT ? = [1]::INTEGER[] AS list, ? = TIMESTAMP \'2026-01-01 10:00:00.123456\' AS temporal, ?.price = 1.24 AS nested', [$list, $timestamp, $nested])->fetchRow();
var_dump($row === ['list' => true, 'temporal' => true, 'nested' => true]);
foreach (["INTEGER; SELECT 1", "INTEGER -- comment", "INTEGER/*comment*/", "INTEGER\0", "INTEGER trailing", "INTEGER + 1", "INTEGER[] garbage", "ENUM('x'); DROP TABLE t", 'STRUCT(a INTEGER', 'ANY', 'INVALID', 'INTEGER_LITERAL', 'STRING_LITERAL'] as $type) {
    try { new Value($type, null); echo "unsafe accepted: $type\n"; }
    catch (ValueError $e) { echo "declaration rejected\n"; }
}
$cycle = [];
$cycle[] = &$cycle;
try { new Value('INTEGER[]', $cycle); echo "cycle accepted\n"; }
catch (ValueError $e) { echo "cycle rejected\n"; }
// Release the exception trace's argument reference before collecting the cycle.
unset($e, $cycle);
gc_collect_cycles();
try { new Value('INTEGER', new stdClass()); echo "object accepted\n"; }
catch (TypeError $e) { echo "object rejected\n"; }
$deep = 1;
for ($depth = 0; $depth < 513; $depth++) { $deep = [$deep]; }
try { new Value('VARIANT', $deep); echo "deep input accepted\n"; }
catch (ValueError $e) { echo "deep input rejected\n"; }
$declaration = str_repeat('STRUCT(a ', 513) . 'INTEGER' . str_repeat(')', 513);
try { new Value($declaration, null); echo "deep declaration accepted\n"; }
catch (ValueError $e) { echo "deep declaration rejected\n"; }
try { serialize($list); echo "serialization accepted\n"; }
catch (Throwable $e) { echo "serialization rejected\n"; }
try {
    $unserialized = @unserialize('O:12:"DuckDB\\Value":0:{}');
    echo $unserialized === false ? "unserialization rejected\n" : "unserialization accepted\n";
} catch (Throwable $e) { echo "unserialization rejected\n"; }
echo "done\n";
?>
--EXPECT--
DECIMAL(18, 2)
mutation rejected
bool(true)
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
declaration rejected
cycle rejected
object rejected
deep input rejected
deep declaration rejected
serialization rejected
unserialization rejected
done
