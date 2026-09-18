--TEST--
Binding: type errors for unsupported/invalid values
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// Resources cannot be converted
try {
    $conn->execute('SELECT ?', [fopen('php://memory', 'r')]);
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}

// Plain objects cannot be converted
try {
    $conn->execute('SELECT ?', [new stdClass]);
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}

// Empty lists cannot be typed
try {
    $conn->execute('SELECT ?', [[]]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// All-NULL lists cannot be typed
try {
    $conn->execute('SELECT ?', [[null, null]]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// Heterogeneous lists are rejected (element 0 int, element 1 string)
try {
    $conn->execute('SELECT ?', [[1, 'two']]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// Heterogeneous nested depth is fine when types match: int vs float is not
try {
    $conn->execute('SELECT ?', [[1, 2.5]]);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}

// Out-of-range positional index
$stmt = $conn->prepare('SELECT ?::INT');
try {
    $stmt->bindValue(2, 1);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
try {
    $stmt->bindValue(0, 1);
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
?>
--EXPECTF--
TypeError: Cannot convert a value of type resource to a DuckDB value
TypeError: Cannot convert an object of class stdClass to a DuckDB value (supported: DuckDB\Interval, DateTimeInterface)
ValueError: Cannot convert an empty array to a DuckDB LIST (the element type cannot be inferred)
ValueError: Cannot convert an array of only NULL values to a DuckDB LIST (the element type cannot be inferred)
ValueError: Cannot convert array to a DuckDB LIST: element 1 has a different type than the inferred element type BIGINT
ValueError: Cannot convert array to a DuckDB LIST: element 1 has a different type than the inferred element type BIGINT
ValueError: %s
ValueError: %s
