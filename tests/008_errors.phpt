--TEST--
Errors: typed exception hierarchy
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\{Database, Exception, ParserException, CatalogException, BinderException,
    ConstraintException, ConversionException, TransactionException, ErrorType};

$conn = (new Database())->connect();

// All driver exceptions share the base class and carry an ErrorType code
try {
    $conn->query('SELEC 1');
} catch (ParserException $e) {
    echo get_class($e), ' | code=', $e->getCode(), ' | type=', $e->getErrorType()->name, "\n";
    echo ($e instanceof Exception ? 'is DuckDB\Exception' : 'NOT base'),
         ($e instanceof \Exception ? ' | is \Exception' : ''), "\n";
}

try {
    $conn->query('SELECT * FROM missing_table');
} catch (CatalogException $e) {
    echo 'catalog code=', $e->getCode(), "\n";
}

try {
    $conn->query("SELECT 1 + 'a'");
} catch (BinderException $e) {
    echo 'binder code=', $e->getCode(), "\n";
}

$conn->query('CREATE TABLE pk (i INT PRIMARY KEY)');
$conn->query('INSERT INTO pk VALUES (1)');
try {
    $conn->query('INSERT INTO pk VALUES (1)');
} catch (ConstraintException $e) {
    echo 'constraint code=', $e->getCode(), "\n";
}

try {
    $conn->query("SELECT 'abc'::INTEGER");
} catch (ConversionException $e) {
    echo 'conversion code=', $e->getCode(), "\n";
}

try {
    $conn->query('COMMIT');
} catch (TransactionException $e) {
    echo 'transaction code=', $e->getCode(), "\n";
}

// Prepare-time errors are classified from their message prefix
try {
    $conn->prepare('SELECT * FROM gone');
} catch (CatalogException $e) {
    echo 'prepare catalog code=', $e->getCode(), "\n";
}

// ErrorType round-trips through the exception code
try {
    $conn->query('SELEC 1');
} catch (Exception $e) {
    var_dump(ErrorType::tryFrom($e->getCode()));
}
?>
--EXPECT--
DuckDB\ParserException | code=14 | type=Parser
is DuckDB\Exception | is \Exception
catalog code=13
binder code=24
constraint code=18
conversion code=2
transaction code=10
prepare catalog code=13
enum(DuckDB\ErrorType::Parser)