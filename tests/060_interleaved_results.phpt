--TEST--
Result: multiple buffered results fetched interleaved on one connection
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\FetchMode;

$conn = (new Database())->connect();

$a = $conn->query('SELECT i::BIGINT AS i FROM range(3) t(i)');            // 0, 1, 2
$b = $conn->query('SELECT (i + 10)::BIGINT AS i FROM range(3) t(i)');     // 10, 11, 12
$stmt = $conn->prepare('SELECT (i + $1)::BIGINT AS i FROM range(3) t(i)');
$c = $stmt->execute([100]);                                               // 100, 101, 102

// Round-robin fetches stay independent: each result keeps its own cursor
$seq = [];
for ($round = 0; $round < 3; $round++) {
    $seq[] = $a->fetchRow(FetchMode::Num)[0];
    $seq[] = $b->fetchRow(FetchMode::Num)[0];
    $seq[] = $c->fetchRow(FetchMode::Num)[0];
}
echo implode(',', $seq), "\n";
echo 'a exhausted: ', var_export($a->fetchRow(), true), "\n";

// rowCount is stable regardless of fetch progress
$a2 = $conn->query('SELECT i::BIGINT AS i FROM range(5) t(i)');
$a2->fetchRow();
echo 'rowCount after one fetch: ', $a2->rowCount(), "\n";

// DML on the same connection does not disturb open results
$x = $conn->query('SELECT i::BIGINT AS i FROM range(4) t(i)');            // 0..3
$y = $conn->query('SELECT (i * 10)::BIGINT AS i FROM range(4) t(i)');     // 0, 10, 20, 30
$x->fetchRow();
$conn->query('CREATE TABLE t (i INTEGER)');
$conn->query('INSERT INTO t VALUES (1)');
echo 'x resumes: ', $x->fetchRow()['i'], "\n";
echo 'y intact: ', $y->fetchRow()['i'], ',', $y->fetchRow()['i'], "\n";
echo 'x done: ', implode(',', array_column($x->fetchAll(FetchMode::Num), 0)), "\n";

// Manual iterator interleave across two results
$it1 = $conn->query('SELECT i::BIGINT AS i FROM range(2) t(i)')->getIterator();
$it2 = $conn->query('SELECT (i + 5)::BIGINT AS i FROM range(2) t(i)')->getIterator();
$it1->rewind();
$it2->rewind();
echo $it1->current()['i'], ',', $it2->current()['i'], "\n";
$it1->next();
$it2->next();
echo $it1->current()['i'], ',', $it2->current()['i'], "\n";
$it1->next();
$it2->next();
var_dump($it1->valid());
var_dump($it2->valid());
echo "done\n";
?>
--EXPECT--
0,10,100,1,11,101,2,12,102
a exhausted: NULL
rowCount after one fetch: 5
x resumes: 1
y intact: 0,10
x done: 2,3
0,5
1,6
bool(false)
bool(false)
done
