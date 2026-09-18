--TEST--
Unicode: identifiers, string values, parameters
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$conn->query('CREATE TABLE "táblé" ("colümn" VARCHAR)');
$conn->execute('INSERT INTO "táblé" VALUES (?)', ['héllo wörld 🦆']);
$row = $conn->query('SELECT "colümn" FROM "táblé"')->fetchRow();
var_dump($row['colümn']);

$row = $conn->execute('SELECT ?::VARCHAR AS s', ['日本語 🦆 éèê'])->fetchRow();
var_dump($row['s']);
?>
--EXPECT--
string(18) "héllo wörld 🦆"
string(21) "日本語 🦆 éèê"
