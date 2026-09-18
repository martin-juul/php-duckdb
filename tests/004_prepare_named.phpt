--TEST--
Statement: named parameters ($name, :name)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$stmt = $conn->prepare('SELECT $value::INTEGER AS v, $label::VARCHAR AS l');
var_dump($stmt->parameterCount());
var_dump($stmt->parameterName(1));
var_dump($stmt->parameterName(2));

// Names work with and without the $ prefix
$stmt->bindValue('value', 7);
$stmt->bindValue('$label', 'seven');
var_dump($stmt->execute()->fetchRow());

// Associative array binding
var_dump($stmt->execute(['value' => 8, 'label' => 'eight'])->fetchRow());

// Unknown parameter name
try {
    $stmt->bindValue('nope', 1);
} catch (DuckDB\BinderException $e) {
    echo 'BinderException caught', "\n";
}
?>
--EXPECT--
int(2)
string(5) "value"
string(5) "label"
array(2) {
  ["v"]=>
  int(7)
  ["l"]=>
  string(5) "seven"
}
array(2) {
  ["v"]=>
  int(8)
  ["l"]=>
  string(5) "eight"
}
BinderException caught
