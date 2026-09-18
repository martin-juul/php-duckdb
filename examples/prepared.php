<?php
// Prepared statements + both async modes.

use DuckDB\Database;

require __DIR__ . '/bootstrap.php';

$db = new Database(':memory:');
$con = $db->connect();

$con->query('CREATE TABLE ducks (id INTEGER, name VARCHAR)');

$insert = $con->prepare('INSERT INTO ducks VALUES (?, ?)');
foreach ([[1, 'Quackers'], [2, 'Waddles'], [3, 'Feathers']] as $i => [$id, $name]) {
    $insert->bindValue(1, $id)->bindValue(2, $name)->execute();
}

$select = $con->prepare('SELECT name FROM ducks WHERE id = ?');
$select->bindValue(1, 2);
printf("sync:  %s\n", $select->execute()->fetchRow()['name']);

// Async via worker thread:
$select->bindValue(1, 3);
printf("async: %s\n", $select->executeAsync()->await()->fetchRow()['name']);

// Single-threaded polling mode (no worker threads):
$pending = $con->queryPending('SELECT count(*) AS n FROM ducks');
while (!$pending->isReady()) {
    usleep(100);
}
printf("polling: count = %d\n", $pending->await()->fetchRow()['n']);
