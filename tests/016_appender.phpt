--TEST--
Appender: bulk inserts, piecemeal rows, defaults, error handling
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// appendRow
$conn->query('CREATE TABLE app (a INT, b VARCHAR, c DOUBLE)');
$appender = $conn->appender('app');
for ($i = 0; $i < 1000; $i++) {
    $appender->appendRow([$i, "row$i", $i * 0.5]);
}
$appender->flush();
$appender->close();
var_dump($conn->query('SELECT count(*)::INT AS c, sum(a)::INT AS s FROM app')->fetchRow());

// Piecemeal rows with a column default
$conn->query("CREATE TABLE appd (a INT, b VARCHAR DEFAULT 'dflt', c DOUBLE)");
$appender = $conn->appender('appd');
$appender->beginRow();
$appender->append(7);
$appender->appendDefault();
$appender->append(1.5);
$appender->endRow();
$appender->close();
var_dump($conn->query('SELECT * FROM appd')->fetchRow());

// close() is idempotent; a closed appender rejects work
$appender->close();
try {
    $appender->appendRow([1, 'x', 1.0]);
} catch (DuckDB\Exception $e) {
    echo 'closed: ', $e->getMessage(), "\n";
}

// Wrong value count
$appender = $conn->appender('app');
try {
    $appender->appendRow([1, 2]);
} catch (ValueError $e) {
    echo 'count: ValueError', "\n";
}

// Row discipline
try {
    $appender->append(1);
} catch (Error $e) {
    echo 'no open row: Error', "\n";
}
$appender->beginRow();
try {
    $appender->beginRow();
} catch (Error $e) {
    echo 'double begin: Error', "\n";
}

// Missing table
try {
    $conn->appender('no_such_table');
} catch (DuckDB\CatalogException $e) {
    echo 'missing table: CatalogException', "\n";
}
?>
--EXPECT--
array(2) {
  ["c"]=>
  int(1000)
  ["s"]=>
  int(499500)
}
array(3) {
  ["a"]=>
  int(7)
  ["b"]=>
  string(4) "dflt"
  ["c"]=>
  float(1.5)
}
closed: Appender is closed
count: ValueError
no open row: Error
double begin: Error
missing table: CatalogException
