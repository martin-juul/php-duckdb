--TEST--
DuckDB 1.5.6 optimistic writes can be configured from PHP and SQL
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
// Distribution packages can use an older system DuckDB.
$c = (new DuckDB\Database())->connect();
if ($c->query("SELECT count(*) AS n FROM duckdb_settings() WHERE name = 'enable_optimistic_write'")->fetchRow()['n'] === 0) {
    die('skip DuckDB does not expose enable_optimistic_write');
}
?>
--FILE--
<?php
$default = (new DuckDB\Database())->connect();
var_dump($default->query("SELECT current_setting('enable_optimistic_write') AS enabled")->fetchRow()['enabled']);

$db = new DuckDB\Database(':memory:', ['enable_optimistic_write' => false]);
$c = $db->connect();
var_dump($c->query("SELECT current_setting('enable_optimistic_write') AS enabled")->fetchRow()['enabled']);
$c->query('CREATE TABLE items (id INTEGER)');
$appender = $c->appender('items');
$appender->appendRow([42]);
$appender->close();
var_dump($c->query('SELECT id FROM items')->fetchRow()['id']);
$c->query('SET enable_optimistic_write = true');
var_dump($c->query("SELECT current_setting('enable_optimistic_write') AS enabled")->fetchRow()['enabled']);
?>
--EXPECT--
bool(true)
bool(false)
int(42)
bool(true)
