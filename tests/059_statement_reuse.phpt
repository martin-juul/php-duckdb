--TEST--
Statement: reuse across executes — changing types, persistent bindings, mixed styles
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Database;
use DuckDB\Exception;

$conn = (new Database())->connect();

// Same parameter, different PHP type per execute
$stmt = $conn->prepare('SELECT $1::VARCHAR AS v');
var_dump($stmt->execute([42])->fetchRow()['v']);
var_dump($stmt->execute(['hello'])->fetchRow()['v']);
var_dump($stmt->execute([null])->fetchRow()['v']);
var_dump($stmt->execute([true])->fetchRow()['v']);
var_dump($stmt->execute([1.5])->fetchRow()['v']);

// Bindings persist across executes; execute(array) rebinds only the
// positions/keys it carries.
$stmt = $conn->prepare('SELECT $1::INTEGER + $2::INTEGER AS total');
$stmt->bindValue(2, 100);
var_dump($stmt->execute([50])->fetchRow()['total']);
var_dump($stmt->execute()->fetchRow()['total']);
$stmt->bindValue(1, 1);
$stmt->bindValue(2, 2);
var_dump($stmt->execute()->fetchRow()['total']);

// Named and positional styles on one statement; an integer key in an
// associative array is a 1-based position.
$stmt = $conn->prepare('SELECT $a::INTEGER + $b::INTEGER AS total');
var_dump($stmt->execute(['a' => 5, 'b' => 6])->fetchRow()['total']);
var_dump($stmt->execute(['b' => 60])->fetchRow()['total']);
var_dump($stmt->execute([1 => 7])->fetchRow()['total']);

// Sustained reuse: 1000 executes on one statement, checksum over results
$stmt = $conn->prepare('SELECT ($1::INTEGER * 2) AS v');
$sum = 0;
for ($i = 0; $i < 1000; $i++) {
    $sum += $stmt->execute([$i])->fetchRow()['v'];
}
echo "reuse loop: $sum\n";

// clearBindings unbinds; the statement is still reusable afterwards
$stmt->clearBindings();
try {
    $stmt->execute();
} catch (Exception) {
    echo "unbound after clear\n";
}
$stmt->bindValue(1, 21);
var_dump($stmt->execute()->fetchRow()['v']);
echo "done\n";
?>
--EXPECT--
string(2) "42"
string(5) "hello"
NULL
string(4) "true"
string(3) "1.5"
int(150)
int(150)
int(3)
int(11)
int(65)
int(67)
reuse loop: 999000
unbound after clear
int(42)
done
