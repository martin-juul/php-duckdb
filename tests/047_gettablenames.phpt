--TEST--
getTableNames: extraction works, unparseable SQL raises ParserException
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE gtn_users (id INTEGER)');
$conn->query('CREATE TABLE gtn_orders (id INTEGER)');

$names = $conn->getTableNames('SELECT * FROM gtn_users');
sort($names);
echo "single: ", implode(',', $names), "\n";

$names = $conn->getTableNames('SELECT * FROM gtn_users JOIN gtn_orders ON gtn_users.id = gtn_orders.id');
sort($names);
echo "join: ", implode(',', $names), "\n";

// unparseable SQL must throw, not silently return an empty list
try {
    $conn->getTableNames('SELEC nonsense');
    echo "invalid: NOT REJECTED\n";
} catch (DuckDB\ParserException $e) {
    echo "invalid: ParserException\n";
}
echo "done\n";
?>
--EXPECT--
single: gtn_users
join: gtn_orders,gtn_users
invalid: ParserException
done
