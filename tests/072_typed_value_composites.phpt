--TEST--
Typed values: nested composites, native UNION tags, VARIANT source types and connection-local catalogs
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Value;
$conn = (new DuckDB\Database())->connect();
function check(DuckDB\Connection $conn, string $sql, array $values): void {
    if ($conn->execute($sql, $values)->fetchRow()['ok'] !== true) {
        throw new RuntimeException($sql);
    }
}
$union = new Value('UNION(i INTEGER, s VARCHAR)', ['tag' => 's', 'value' => null]);
check($conn, "SELECT union_tag(\$v) = 's' AND union_extract(\$v, 's') IS NULL AS ok", ['v' => $union]);
$map = new Value('MAP(VARCHAR, INTEGER)', [['key' => '01', 'value' => 1], ['key' => '1', 'value' => 2]]);
check($conn, "SELECT map_keys(\$v) = ['01','1'] AND map_values(\$v) = [1,2] AS ok", ['v' => $map]);
$map = new Value('MAP(INTEGER[], VARCHAR)', [['key' => [1,2], 'value' => 'first'], ['key' => [], 'value' => 'empty']]);
check($conn, "SELECT map_keys(\$v) = [[1,2],[]] AND map_values(\$v) = ['first','empty'] AS ok", ['v' => $map]);
$struct = new Value('STRUCT(b VARCHAR, a INTEGER[])', ['a' => [null,null], 'b' => 'x']);
check($conn, "SELECT typeof(\$v) = 'STRUCT(b VARCHAR, a INTEGER[])' AND \$v.a = [NULL,NULL]::INTEGER[] AS ok", ['v' => $struct]);
$plain = new Value('VARIANT', '{"a":1}');
check($conn, "SELECT variant_typeof(\$v) = 'VARCHAR' AND \$v::VARCHAR = '{\"a\":1}' AS ok", ['v' => $plain]);
$typed = new Value('VARIANT', new Value('JSON', '{"a":1}'));
check($conn, "SELECT variant_typeof(\$v) = variant_typeof('{\"a\":1}'::JSON::VARIANT) AND \$v = '{\"a\":1}'::JSON::VARIANT AS ok", ['v' => $typed]);
$nested = new Value('VARIANT', ['n' => new Value('UTINYINT', 7), 's' => '7', 'empty' => new Value('INTEGER[]', [])]);
check($conn, "SELECT variant_typeof(\$v.n) = 'UINT8' AND variant_typeof(\$v.s) = 'VARCHAR' AND \$v.n::INTEGER = 7 AS ok", ['v' => $nested]);
$label = "x'); -- ; DROP TABLE innocent";
$quotedType = "ENUM('x''); -- ; DROP TABLE innocent', 'normal')";
check($conn, 'SELECT ?::VARCHAR = ? AS ok', [new Value($quotedType, $label), $label]);
$quotedStruct = new Value('STRUCT("odd; -- field" INTEGER)', ['odd; -- field' => 1]);
check($conn, 'SELECT $v."odd; -- field" = 1 AS ok', ['v' => $quotedStruct]);
echo "native composites: ok\n";

// SQL aliases canonicalize natively, without restricting constructor names.
foreach (['INT', 'INT4', 'SIGNED', 'REAL', 'TEXT', 'STRING', 'BYTEA', 'TIMESTAMP WITH TIME ZONE'] as $type) {
    $value = new Value($type, null);
    check($conn, "SELECT typeof(?) = typeof(NULL::$type) AS ok", [$value]);
}
$conn->query('BEGIN');
$conn->query("CREATE TYPE mood AS ENUM ('happy', 'sad')");
$enum = new Value('mood', 'happy');
check($conn, "SELECT \$v = 'happy'::mood AND typeof(\$v) = typeof(NULL::mood) AS ok", ['v' => $enum]);
$conn->query('ROLLBACK');
try { $conn->execute('SELECT ?', [$enum]); echo "rolled-back type accepted\n"; }
catch (DuckDB\Exception $e) { echo "rolled-back type rejected\n"; }
$conn->query('CREATE TYPE amount AS DECIMAL(10,2)');
$other = (new DuckDB\Database())->connect();
$other->query('CREATE TYPE amount AS DECIMAL(12,3)');
$reusable = new Value('amount', '1.235');
check($conn, "SELECT typeof(\$v) = 'DECIMAL(10,2)' AND \$v = 1.24 AS ok", ['v' => $reusable]);
check($other, "SELECT typeof(\$v) = 'DECIMAL(12,3)' AND \$v = 1.235 AS ok", ['v' => $reusable]);
$conn->query('CREATE SCHEMA custom');
$conn->query('CREATE TYPE custom."Odd Type" AS INTEGER[]');
check($conn, 'SELECT len(?) = 0 AS ok', [new Value('custom."Odd Type"', [])]);
echo "catalog reuse: ok\n";

foreach ([
    ['INTEGER[]', ['a' => 1]],
    ['INTEGER[2]', [1]],
    ['STRUCT(a INTEGER, b INTEGER)', ['a' => 1]],
    ['STRUCT(a INTEGER)', ['a' => 1, 'extra' => 2]],
    ['MAP(INTEGER, VARCHAR)', [['key' => 1]]],
    ['UNION(a INTEGER, b VARCHAR)', ['tag' => 'missing', 'value' => 1]],
    ['UNION(a INTEGER)', ['tag' => 'a']],
] as [$type, $input]) {
    try { $conn->execute('SELECT ?', [new Value($type, $input)]); echo "shape accepted\n"; }
    catch (ValueError $e) { echo "shape rejected\n"; }
}
foreach ([
    [['key' => '1', 'value' => 'a'], ['key' => 1, 'value' => 'b']],
    [['key' => null, 'value' => 'a']],
] as $input) {
    try { $conn->execute('SELECT ?', [new Value('MAP(INTEGER, VARCHAR)', $input)]); echo "map key accepted\n"; }
    catch (DuckDB\Exception $e) { echo "map key rejected\n"; }
}
?>
--EXPECT--
native composites: ok
rolled-back type rejected
catalog reuse: ok
shape rejected
shape rejected
shape rejected
shape rejected
shape rejected
shape rejected
shape rejected
map key rejected
map key rejected
