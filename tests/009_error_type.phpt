--TEST--
ErrorType: enum mirrors the duckdb_error_type C enum
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\ErrorType;

// 45 categories, contiguous 0..44
$cases = ErrorType::cases();
var_dump(count($cases));
$values = array_map(fn($c) => $c->value, $cases);
var_dump($values === range(0, 44));

// Spot-check names
echo ErrorType::Invalid->value, ' ', ErrorType::Parser->value, ' ',
     ErrorType::Constraint->value, ' ', ErrorType::InvalidConfiguration->value, ' ',
     ErrorType::DataCorruption->value, ' ', ErrorType::ResourceInUse->value, "\n";
var_dump(ErrorType::from(29));
var_dump(ErrorType::tryFrom(100));

// DuckDB 2.0 reports an open result that holds the connection as code 44
$e = new DuckDB\Exception('Resource In Use Error: connection has an open result; drain or destroy it before starting a new query', 44);
var_dump($e->getErrorType());
$e = new DuckDB\Exception('Data Corruption Error: Data corruption detected', 43);
var_dump($e->getErrorType());
?>
--EXPECT--
int(45)
bool(true)
0 14 18 42 43 44
enum(DuckDB\ErrorType::Interrupt)
NULL
enum(DuckDB\ErrorType::ResourceInUse)
enum(DuckDB\ErrorType::DataCorruption)
