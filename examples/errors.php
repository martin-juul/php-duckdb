<?php
// Typed error handling.

use DuckDB\{Database, Exception, ErrorType};
use DuckDB\{CatalogException, ConstraintException, ConversionException, ParserException};

require __DIR__ . '/bootstrap.php';

$conn = (new Database())->connect();

$conn->query('CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR NOT NULL)');
$conn->execute('INSERT INTO users VALUES (?, ?)', [1, 'quack@duckdb.org']);

// 1. Catch specific failure modes
try {
    $conn->query('INSERT INTO users VALUES (1, NULL)');
} catch (ConstraintException $e) {
    echo "constraint: {$e->getMessage()}\n";
}

try {
    $conn->query('SELECT * FROM missing_table');
} catch (CatalogException $e) {
    echo "catalog: {$e->getMessage()}\n";
}

try {
    $conn->query('SELEC 1');
} catch (ParserException $e) {
    echo "parser: syntax error\n";
}

// 2. Or branch on the machine-readable category
try {
    $conn->query("SELECT 'not a number'::INTEGER");
} catch (Exception $e) {
    $type = $e->getErrorType();
    if ($type === ErrorType::Conversion) {
        echo "conversion error (code {$e->getCode()})\n";
    }
}

// 3. Programmer errors use native PHP error types
try {
    $conn->execute('SELECT ?::INTEGER', [1, 2, 3]); // too many values
} catch (ValueError | Exception $e) {
    echo get_class($e), ": {$e->getMessage()}\n";
}
