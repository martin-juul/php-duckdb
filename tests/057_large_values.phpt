--TEST--
Values: multi-megabyte BLOB/VARCHAR round-trips and large LIST/MAP containers
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;

$conn = (new Database())->connect();
$conn->query('CREATE TABLE big (id INTEGER, b BLOB, s VARCHAR)');

// 8 MiB of deterministic binary covering every byte value, NUL included.
$block = '';
for ($i = 0; $i < 256; $i++) {
    $block .= chr($i);
}
$bin = str_repeat($block, 32768); // 256 B x 32768 = 8 MiB
echo 'blob contains NUL: ', str_contains($bin, "\0") ? 'yes' : 'no', "\n";

// 4.6 MiB of text
$text = str_repeat('The quick brown fox jumps over the lazy duck. ', 100000);

$stmt = $conn->prepare('INSERT INTO big VALUES ($1, $2, $3)');
$stmt->bindValue(1, 1);
$stmt->bindBlob(2, $bin);
$stmt->bindValue(3, $text);
$stmt->execute();

$row = $conn->query('SELECT b, s FROM big WHERE id = 1')->fetchRow();
echo 'blob length: ', strlen($row['b']), "\n";
echo 'blob identical: ', $row['b'] === $bin ? 'yes' : 'NO', "\n";
echo 'blob sha256: ', hash('sha256', $row['b']), "\n";
echo 'text length: ', strlen($row['s']), "\n";
echo 'text identical: ', $row['s'] === $text ? 'yes' : 'NO', "\n";

// The scalar fetch path handles megabyte values too
echo 'fetchColumn: ', $conn->query('SELECT b FROM big WHERE id = 1')->fetchColumn() === $bin
    ? 'identical' : 'NO', "\n";

// Large containers: a 10k-element list and a 1k-entry map
$row = $conn->query('SELECT list(i ORDER BY i)::INTEGER[] AS lst FROM range(10000) t(i)')->fetchRow();
$lst = $row['lst'];
echo 'list: count=', count($lst), ' sum=', array_sum($lst), " edges={$lst[0]}/{$lst[9999]}\n";

$row = $conn->query(
    'SELECT map(list(i ORDER BY i), list((i * 2) ORDER BY i)) AS m FROM range(1000) t(i)'
)->fetchRow();
$m = $row['m'];
echo 'map: count=', count($m), " m[500]={$m[500]}\n";
echo "done\n";
?>
--EXPECT--
blob contains NUL: yes
blob length: 8388608
blob identical: yes
blob sha256: 7d212b9c884f5c77896de960ae17cc341cda43b14d6a971f34ca29ebd4badf7f
text length: 4600000
text identical: yes
fetchColumn: identical
list: count=10000 sum=49995000 edges=0/9999
map: count=1000 m[500]=1000
done
