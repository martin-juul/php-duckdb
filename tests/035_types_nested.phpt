--TEST--
Types: nested/composite types (LIST, STRUCT, MAP, ARRAY, UNION)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// List of lists
var_dump($conn->query("SELECT [[1,2],[3]]::INT[][] AS v")->fetchRow()['v']);

// Deeply nested struct with NULL
var_dump($conn->query("SELECT {'a': {'x': [1, 2]}, 'b': NULL} AS v")->fetchRow()['v']);

// MAP with scalar keys -> assoc array
var_dump($conn->query("SELECT MAP {'k1': 1, 'k2': 2} AS v")->fetchRow()['v']);

// MAP with non-scalar keys -> list of key/value pairs
var_dump($conn->query("SELECT MAP {[1,2]: 'a'} AS v")->fetchRow()['v']);

// Fixed-size ARRAY
var_dump($conn->query("SELECT ['a','b']::VARCHAR[2] AS v")->fetchRow()['v']);

// UNION decodes to the member value
var_dump($conn->query("SELECT 'txt'::UNION(num INT, txt VARCHAR) AS v")->fetchRow()['v']);
var_dump($conn->query("SELECT 42::UNION(num INT, txt VARCHAR) AS v")->fetchRow()['v']);
var_dump($conn->query("SELECT NULL::UNION(num INT, txt VARCHAR) AS v")->fetchRow()['v']);

// List of structs
var_dump($conn->query("SELECT [{'a': 1}, {'a': 2}] AS v")->fetchRow()['v']);

// Struct containing a map containing a list
var_dump($conn->query("SELECT {'m': MAP {'k': [1, 2]}} AS v")->fetchRow()['v']);

// Empty list from SQL decodes fine
var_dump($conn->query("SELECT []::INT[] AS v")->fetchRow()['v']);
?>
--EXPECT--
array(2) {
  [0]=>
  array(2) {
    [0]=>
    int(1)
    [1]=>
    int(2)
  }
  [1]=>
  array(1) {
    [0]=>
    int(3)
  }
}
array(2) {
  ["a"]=>
  array(1) {
    ["x"]=>
    array(2) {
      [0]=>
      int(1)
      [1]=>
      int(2)
    }
  }
  ["b"]=>
  NULL
}
array(2) {
  ["k1"]=>
  int(1)
  ["k2"]=>
  int(2)
}
array(1) {
  [0]=>
  array(2) {
    ["key"]=>
    array(2) {
      [0]=>
      int(1)
      [1]=>
      int(2)
    }
    ["value"]=>
    string(1) "a"
  }
}
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
string(3) "txt"
int(42)
NULL
array(2) {
  [0]=>
  array(1) {
    ["a"]=>
    int(1)
  }
  [1]=>
  array(1) {
    ["a"]=>
    int(2)
  }
}
array(1) {
  ["m"]=>
  array(1) {
    ["k"]=>
    array(2) {
      [0]=>
      int(1)
      [1]=>
      int(2)
    }
  }
}
array(0) {
}
