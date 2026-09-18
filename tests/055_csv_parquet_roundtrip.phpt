--TEST--
Interop: CSV import, transform, Parquet export and re-import are lossless
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;

$dir = sys_get_temp_dir() . '/duckdb_phpt_' . uniqid();
mkdir($dir);
$csv = $dir . '/in.csv';
$parquet = $dir . '/out.parquet';

// Deterministic CSV, 1000 rows
$fh = fopen($csv, 'w');
fwrite($fh, "id,category,amount\n");
for ($i = 0; $i < 1000; $i++) {
    fwrite($fh, $i . ',cat' . ($i % 10) . ',' . ($i * 1.5) . "\n");
}
fclose($fh);

$conn = (new Database())->connect();
$conn->query(
    "CREATE TABLE t AS SELECT * FROM read_csv('$csv', header = true,
        columns = {'id': 'INTEGER', 'category': 'VARCHAR', 'amount': 'DOUBLE'})"
);
echo 'imported: ', $conn->query('SELECT count(*)::INTEGER AS c FROM t')->fetchRow()['c'], "\n";

// Transform
$agg = $conn->query(
    'SELECT category, count(*)::INTEGER AS n, sum(amount)::BIGINT AS total
     FROM t GROUP BY category ORDER BY category'
)->fetchAll();
foreach ($agg as $r) {
    echo "{$r['category']}: {$r['n']} {$r['total']}\n";
}

// Export to Parquet; a real Parquet file starts with PAR1
$conn->query("COPY (SELECT * FROM t) TO '$parquet' (FORMAT PARQUET)");
echo 'parquet magic: ', file_get_contents($parquet, false, null, 0, 4) === 'PAR1' ? 'PAR1' : 'BROKEN', "\n";

// Re-import; the round-trip must be lossless in both directions
$conn->query("CREATE TABLE p AS SELECT * FROM parquet_scan('$parquet')");
echo 're-imported: ', $conn->query('SELECT count(*)::INTEGER AS c FROM p')->fetchRow()['c'], "\n";
$diff = $conn->query(
    'SELECT (SELECT count(*) FROM (SELECT * FROM t EXCEPT SELECT * FROM p)) +
            (SELECT count(*) FROM (SELECT * FROM p EXCEPT SELECT * FROM t)) AS d'
)->fetchRow()['d'];
echo "symmetric diff: $diff\n";

// Column types survive the file-format round-trip
$cols = $conn->query(
    "SELECT column_name, data_type FROM information_schema.columns
     WHERE table_name = 'p' ORDER BY ordinal_position"
)->fetchAll();
echo 'types: ', implode(' ', array_map(
    static fn (array $c): string => $c['column_name'] . '=' . $c['data_type'],
    $cols,
)), "\n";

unlink($csv);
unlink($parquet);
rmdir($dir);
echo "done\n";
?>
--EXPECT--
imported: 1000
cat0: 100 74250
cat1: 100 74400
cat2: 100 74550
cat3: 100 74700
cat4: 100 74850
cat5: 100 75000
cat6: 100 75150
cat7: 100 75300
cat8: 100 75450
cat9: 100 75600
parquet magic: PAR1
re-imported: 1000
symmetric diff: 0
types: id=INTEGER category=VARCHAR amount=DOUBLE
done
