--TEST--
Appender: sustained 100k-row bulk load with mid-load flushes and mixed types
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;

$db = new Database();
$conn = $db->connect();

$conn->query('CREATE TABLE bulk (a INTEGER, b VARCHAR, c DOUBLE, d BOOLEAN, e INTEGER)');

// 100k rows across four explicit flushes; every PHP type in the mix,
// including nulls.
$appender = $conn->appender('bulk');
for ($i = 0; $i < 100000; $i++) {
    $appender->appendRow([$i, 'row' . ($i % 1000), $i * 0.25, $i % 2 === 0, $i % 10 === 0 ? null : $i]);
    if ($i % 25000 === 24999) {
        $appender->flush();
    }
}
$appender->close();

$row = $conn->query(
    'SELECT count(*)::INTEGER AS cnt, sum(a)::BIGINT AS sa, sum(c)::BIGINT AS sc,
            sum(length(b))::INTEGER AS sl, count(e)::INTEGER AS ce,
            count(*) FILTER (WHERE d)::INTEGER AS trues
     FROM bulk'
)->fetchRow();
var_dump($row);

// A second connection on the same Database sees the bulk load
$conn2 = $db->connect();
var_dump($conn2->query('SELECT a, b, c FROM bulk WHERE a = 99999')->fetchRow());

// Piecemeal rows at scale, with the default used every time
$conn->query("CREATE TABLE piece (a INTEGER, b VARCHAR DEFAULT 'dflt', c INTEGER)");
$appender = $conn->appender('piece');
for ($i = 0; $i < 20000; $i++) {
    $appender->beginRow();
    $appender->append($i);
    $appender->appendDefault();
    $appender->append($i % 7);
    $appender->endRow();
}
$appender->flush();
$appender->close();
$row = $conn->query(
    "SELECT count(*)::INTEGER AS cnt, sum(c)::BIGINT AS sc,
            count(*) FILTER (WHERE b = 'dflt')::INTEGER AS defs
     FROM piece"
)->fetchRow();
echo "piecemeal: cnt={$row['cnt']} sum_c={$row['sc']} defaults={$row['defs']}\n";
echo "done\n";
?>
--EXPECT--
array(6) {
  ["cnt"]=>
  int(100000)
  ["sa"]=>
  int(4999950000)
  ["sc"]=>
  int(1249987500)
  ["sl"]=>
  int(319000)
  ["ce"]=>
  int(90000)
  ["trues"]=>
  int(50000)
}
array(3) {
  ["a"]=>
  int(99999)
  ["b"]=>
  string(6) "row999"
  ["c"]=>
  float(24999.75)
}
piecemeal: cnt=20000 sum_c=59997 defaults=20000
done
