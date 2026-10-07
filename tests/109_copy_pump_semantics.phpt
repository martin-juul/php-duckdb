--TEST--
COPY pump: forced-pump execution keeps query semantics and every pending exit
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--ENV--
DUCKDB_PHP_TEST_FORCE_PUMP=1
--FILE--
<?php

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, string $class): string
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected ' . get_class($error) . ': ' . $error->getMessage());
        return get_class($error);
    }

    throw new RuntimeException('Expected rejection');
}

$conn = (new DuckDB\Database())->connect();
check($conn->query('SELECT 1 AS a; SELECT 2 AS b')->fetchAll() === [['a' => 1]], 'The first statement result is returned');
check($conn->query('')->fetchAll() === [] && $conn->query(' ; ')->fetchAll() === [], 'Empty input is accepted');
$conn->query('CREATE TABLE t(i INTEGER PRIMARY KEY); INSERT INTO t VALUES (1), (2); SET threads = 2');
check($conn->query('SELECT count(*) AS n FROM t')->fetchAll() === [['n' => 2]], 'Every statement runs in order');
echo "multi-statement queries run every statement\n";

$classes = [];
$classes[] = rejects(fn() => $conn->query('CREATE TABLE never(i INTEGER); SELEC 1'), DuckDB\ParserException::class);
$classes[] = rejects(
    fn() => $conn->query("CREATE TABLE first(i INTEGER); SELECT 'z'::INTEGER; CREATE TABLE after(i INTEGER)"),
    DuckDB\ConversionException::class
);
check($conn->query("SELECT table_name FROM duckdb_tables() WHERE table_name IN ('first', 'after')")->fetchAll()
    === [['table_name' => 'first']], 'Statements stop at the first error');
check($conn->query("SELECT count(*) AS n FROM duckdb_tables() WHERE table_name = 'never'")->fetchAll() === [['n' => 0]],
    'A parse error runs no statement');
$classes[] = rejects(fn() => $conn->query('INSERT INTO t VALUES (1)'), DuckDB\ConstraintException::class);
$classes[] = rejects(fn() => $conn->query("SELECT 'x'::INTEGER"), DuckDB\ConversionException::class);
$classes[] = rejects(fn() => $conn->query('SELECT * FROM missing'), DuckDB\CatalogException::class);
$classes[] = rejects(fn() => $conn->query('COMMIT'), DuckDB\TransactionException::class);
$classes[] = rejects(fn() => $conn->execute("SELECT ?::INTEGER", ['x']), DuckDB\ConversionException::class);
$classes[] = rejects(
    fn() => $conn->prepare('SELECT ?::INTEGER AS v')->execute(['y']),
    DuckDB\ConversionException::class
);
$classes[] = rejects(function () use ($conn) {
    foreach ($conn->queryStreaming("SELECT 'z'::INTEGER") as $row) {
    }
}, DuckDB\ConversionException::class);
echo implode("\n", $classes), "\n";
check($conn->query('SELECT 3 AS c')->fetchAll() === [['c' => 3]], 'The connection stays usable');

$slow = 'SELECT count(*) AS n FROM range(3000000) a';
$ready = $conn->queryPending($slow);
check($ready->await()->fetchAll() === [['n' => 3000000]], 'A pending query completes');
$failing = $conn->queryPending("SELECT 'q'::INTEGER");
rejects(fn() => $failing->await(), DuckDB\Exception::class);
$cancelled = $conn->queryPending($slow);
$cancelled->cancel();
rejects(fn() => $cancelled->await(), DuckDB\InterruptedException::class);
$interrupted = $conn->queryPending($slow);
$conn->interrupt();
try {
    $interrupted->await();
} catch (DuckDB\Exception $error) {
}
$undriven = $conn->queryPending($slow);
unset($undriven);
$superseded = $conn->queryPending($slow);
check($conn->query('SELECT 4 AS d')->fetchAll() === [['d' => 4]], 'A newer statement supersedes a pending query');
try {
    $superseded->await();
} catch (DuckDB\Exception $error) {
}
$closing = $conn->queryPending($slow);
$conn->close();
unset($closing);
echo "every pending exit completes\n";
?>
--EXPECT--
multi-statement queries run every statement
DuckDB\ParserException
DuckDB\ConversionException
DuckDB\ConstraintException
DuckDB\ConversionException
DuckDB\CatalogException
DuckDB\TransactionException
DuckDB\ConversionException
DuckDB\ConversionException
DuckDB\ConversionException
every pending exit completes
