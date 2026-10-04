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

// 4. Diagnose an invalid report definition separately from malformed SQL.
try {
    $conn->query('SELECT unknown_column FROM users');
} catch (DuckDB\BinderException $e) {
    echo "binder: the report references an unknown column\n";
}

try {
    $conn->query("SELECT 'invalid'::DATE");
} catch (ConversionException $e) {
    echo "conversion: reject the input before retrying\n";
}

// Use a fresh empty directory to make a missing local import deterministic.
$directory = sys_get_temp_dir() . '/duckdb-errors-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create the example directory');
}

try {
    $conn->execute('SELECT * FROM read_csv(?)', [$directory . '/missing.csv']);
} catch (DuckDB\IOException $e) {
    echo "io: import file does not exist\n";
} finally {
    rmdir($directory);
}

$conn->close();
try {
    $conn->query('SELECT 1');
} catch (DuckDB\ConnectionException $e) {
    echo "connection: open a new connection before submitting another query\n";
}

// Error categories are broader than the dedicated PHP exception subclasses.
// Unknown/driver-only categories may be null; never assume every error is SQL.
printf("error categories: %s\n", implode(', ', array_column(ErrorType::cases(), 'name')));
printf("conversion lookup: %s\n", ErrorType::from(2)->name);
var_dump(ErrorType::tryFrom(-1));

// InternalException indicates an engine defect; report its reproducer instead
// of retrying automatically. Intentionally crashing an engine is not an example.
// TransactionException is shown in transactions.php, InterruptedException in
// async_jobs.php; all other dedicated subclasses are caught above.
