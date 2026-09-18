--TEST--
ErrorType: enum mirrors the duckdb_error_type C enum
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\ErrorType;

// 43 categories, contiguous 0..42
$cases = ErrorType::cases();
var_dump(count($cases));
$values = array_map(fn($c) => $c->value, $cases);
var_dump($values === range(0, 42));

// Spot-check names
echo ErrorType::Invalid->value, ' ', ErrorType::Parser->value, ' ',
     ErrorType::Constraint->value, ' ', ErrorType::InvalidConfiguration->value, "\n";
var_dump(ErrorType::from(29));
var_dump(ErrorType::tryFrom(100));
?>
--EXPECT--
int(43)
bool(true)
0 14 18 42
enum(DuckDB\ErrorType::Interrupt)
NULL
