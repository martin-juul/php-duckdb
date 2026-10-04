--TEST--
Typed metadata batches: repeated declarations retain fresh values and operation-local catalog resolution
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Value;
function batchCheck(DuckDB\Connection $conn, string $sql, array $values): void {
    if ($conn->execute($sql, $values)->fetchRow()['ok'] !== true) {
        throw new RuntimeException($sql);
    }
}
$db = new DuckDB\Database();
$conn = $db->connect();
batchCheck($conn, <<<'SQL'
SELECT typeof($a) = 'DECIMAL(10,2)' AND $a = 1.24
   AND typeof($b) = 'DECIMAL(10,2)' AND $b = 9.88
   AND typeof($nil) = 'DECIMAL(10,2)' AND $nil IS NULL
   AND $ordinary = 17 AND $text = 'plain' AND $plain_null IS NULL AS ok
SQL, ['a' => new Value(' decimal ( 10 , 2 ) ', '1.235'),
      'b' => new Value('DECIMAL(10,2)', '9.876'),
      'nil' => new Value('DECIMAL(10, 2)', null),
      'ordinary' => 17, 'text' => 'plain', 'plain_null' => null]);
echo "mixed batch preserves types and values\n";

// The same declaration appears in nested wrappers and top-level parameters.
$nested = new Value('STRUCT(xs INTEGER[], price DECIMAL(10,2))', [
    'xs' => new Value('INTEGER[]', [new Value('INTEGER', 1), 2, new Value('INTEGER', null)]),
    'price' => new Value('DECIMAL(10,2)', '3.456'),
]);
batchCheck($conn, <<<'SQL'
SELECT typeof($nested) = 'STRUCT(xs INTEGER[], price DECIMAL(10,2))'
   AND $nested.xs = [1,2,NULL]::INTEGER[] AND $nested.price = 3.46
   AND typeof($xs) = 'INTEGER[]' AND $xs = [8,9]::INTEGER[]
   AND typeof($price) = 'DECIMAL(10,2)' AND $price = 7.89
   AND variant_typeof($variant.a) = 'INT32' AND $variant.a::INTEGER = 11
   AND variant_typeof($variant.b) = 'INT32' AND $variant.b::INTEGER = 12
   AND variant_typeof($variant.text) = 'VARCHAR' AS ok
SQL, ['nested' => $nested, 'xs' => new Value('INTEGER[]', [8, new Value('INTEGER', 9)]),
      'price' => new Value('DECIMAL(10,2)', '7.891'),
      'variant' => new Value('VARIANT', [
          'a' => new Value('INTEGER', 11), 'b' => new Value('INTEGER', 12), 'text' => 'plain',
      ])]);
echo "nested mixed wrappers preserve native types\n";

// Exceed the metadata bound before resolving recursively nested declarations.
// Both the outer STRUCT and its wrapped children must retain owned metadata
// throughout recursion even when no entry can be added to the cache.
$wideValues = [];
$wideChecks = [];
for ($i = 0; $i < 80; $i++) {
    $type = "STRUCT(f$i INTEGER)";
    $wideValues["v$i"] = new Value($type, ["f$i" => $i]);
    $wideChecks[] = "typeof(\$v$i) = typeof(NULL::$type) AND \$v$i.f$i = $i";
}
$overflowType = 'STRUCT(child STRUCT(n INTEGER), xs INTEGER[])';
foreach ([81, 82] as $n) {
    $wideValues["overflow$n"] = new Value($overflowType, [
        'child' => new Value('STRUCT(n INTEGER)', ['n' => new Value('INTEGER', $n)]),
        'xs' => new Value('INTEGER[]', [$n, null]),
    ]);
    $wideChecks[] = "typeof(\$overflow$n) = typeof(NULL::$overflowType)"
        . " AND \$overflow$n.child.n = $n AND \$overflow$n.xs = [$n,NULL]::INTEGER[]";
}
$wideStmt = $conn->prepare('SELECT ' . implode(' AND ', $wideChecks) . ' AS ok');
if ($wideStmt->execute($wideValues)->fetchRow()['ok'] !== true) {
    throw new RuntimeException('Metadata overflow changed types or values');
}
$invalidWideValues = $wideValues;
$invalidWideValues['overflow82'] = new Value($overflowType, [
    'child' => new Value('STRUCT(n INTEGER)', ['n' => new Value('INTEGER', 'bad')]),
    'xs' => new Value('INTEGER[]', [82, null]),
]);
try {
    $wideStmt->execute($invalidWideValues);
    throw new RuntimeException('Overflow invalid nested cast accepted');
} catch (DuckDB\ConversionException) {
    // Failed recursive conversion must release temporary handles and retain
    // the previous valid deferred bindings for the next operation.
}
if ($wideStmt->execute()->fetchRow()['ok'] !== true) {
    throw new RuntimeException('Metadata overflow failure changed bindings');
}
echo "metadata overflow preserves nested ownership and failure recovery\n";

$conn->query('CREATE TYPE batch_amount AS DECIMAL(10,2)');
$stmt = $conn->prepare('SELECT typeof($a) AS t, $a::VARCHAR AS a, $b::VARCHAR AS b, $nil IS NULL AS nil');
$values = ['a' => new Value('batch_amount', '1.235'),
           'b' => new Value('batch_amount', '9.876'), 'nil' => new Value('batch_amount', null)];
$expected = ['t' => 'DECIMAL(10,2)', 'a' => '1.24', 'b' => '9.88', 'nil' => true];
if ($stmt->execute($values)->fetchRow() !== $expected) { throw new RuntimeException('Initial catalog batch'); }
$conn->query('DROP TYPE batch_amount');
$conn->query('CREATE TYPE batch_amount AS DECIMAL(12,3)');
$expected = ['t' => 'DECIMAL(12,3)', 'a' => '1.235', 'b' => '9.876', 'nil' => true];
if ($stmt->execute()->fetchRow() !== $expected) { throw new RuntimeException('Catalog metadata survived operation'); }
echo "catalog redefinition refreshes repeated values\n";

$other = (new DuckDB\Database())->connect();
$other->query('CREATE TYPE batch_amount AS DECIMAL(8,1)');
batchCheck($other, <<<'SQL'
SELECT typeof($a) = 'DECIMAL(8,1)' AND $a = 1.2
   AND typeof($b) = 'DECIMAL(8,1)' AND $b = 9.9 AND $nil IS NULL AS ok
SQL, $values);
if ($stmt->execute()->fetchRow() !== $expected) { throw new RuntimeException('Other connection changed metadata'); }
// Connections to the same database also resolve their own transaction snapshot.
$observer = $db->connect();
$conn->query('BEGIN');
$conn->query('DROP TYPE batch_amount');
$conn->query('CREATE TYPE batch_amount AS DECIMAL(9,1)');
batchCheck($conn, "SELECT typeof(\$a) = 'DECIMAL(9,1)' AND \$a = 1.2 AND \$b = 9.9 AS ok",
    ['a' => $values['a'], 'b' => $values['b']]);
batchCheck($observer, "SELECT typeof(\$a) = 'DECIMAL(12,3)' AND \$a = 1.235 AND \$b = 9.876 AS ok",
    ['a' => $values['a'], 'b' => $values['b']]);
$conn->query('ROLLBACK');
if ($stmt->execute()->fetchRow() !== $expected) { throw new RuntimeException('Rolled-back metadata retained'); }
echo "connections and rollback isolate catalog metadata\n";

$conn->query('CREATE TABLE batch_rows(a DECIMAL(10,2), b DECIMAL(10,2), xs INTEGER[], note VARCHAR)');
$app = $conn->appender('batch_rows');
$app->appendRow([new Value('DECIMAL(10,2)', '1.235'), new Value('DECIMAL(10,2)', '9.876'),
    new Value('INTEGER[]', [new Value('INTEGER', 1), 2]), 'first']);
$app->appendRow([new Value('DECIMAL(10,2)', null), new Value('DECIMAL(10,2)', '3.456'),
    new Value('INTEGER[]', []), new Value('VARCHAR', 'second')]);
$app->close();
$conn->query('CREATE TABLE batch_mixed(a INTEGER, b VARCHAR, xs INTEGER[])');
$app = $conn->appender('batch_mixed');
$app->appendRow([new Value('INTEGER', 7), new Value('VARCHAR', 'seven'), new Value('INTEGER[]', [7])]);
$app->close();
if ($conn->query("SELECT count(*) AS n FROM batch_rows WHERE
    typeof(a) = 'DECIMAL(10,2)' AND typeof(b) = 'DECIMAL(10,2)' AND typeof(xs) = 'INTEGER[]'
    AND ((note = 'first' AND a = 1.24 AND b = 9.88 AND xs = [1,2]::INTEGER[])
      OR (note = 'second' AND a IS NULL AND b = 3.46 AND xs = []::INTEGER[]))")->fetchRow()['n'] !== 2) {
    throw new RuntimeException('Appender repeated batch changed values');
}
if ($conn->query("SELECT typeof(a) = 'INTEGER' AND a = 7 AND typeof(b) = 'VARCHAR'
    AND b = 'seven' AND typeof(xs) = 'INTEGER[]' AND xs = [7]::INTEGER[] AS ok FROM batch_mixed")->fetchRow()['ok'] !== true) {
    throw new RuntimeException('Appender mixed schema reused metadata');
}
echo "appendRow repeated and mixed schemas preserve values\n";
?>
--EXPECT--
mixed batch preserves types and values
nested mixed wrappers preserve native types
metadata overflow preserves nested ownership and failure recovery
catalog redefinition refreshes repeated values
connections and rollback isolate catalog metadata
appendRow repeated and mixed schemas preserve values
