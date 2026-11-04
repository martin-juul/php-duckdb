--TEST--
Result: statementType() reports the statement's own type on every execution path
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
// DuckDB 2.0 reports EXECUTE for every result of a C API prepared
// statement; the extension reports the prepared statement's own type.
final class Identity implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        return array_column($arguments->toRows(DuckDB\FetchMode::Num), 0);
    }
}

function report(string $path, DuckDB\Result $result): void
{
    echo $path, ': ', $result->statementType(), "\n";
}

$conn = (new DuckDB\Database())->connect();
$conn->query('CREATE TABLE t(i INTEGER)');

report('query insert', $conn->query('INSERT INTO t VALUES (1)'));
report('execute insert', $conn->execute('INSERT INTO t VALUES (?)', [2]));
report('prepared insert', $conn->prepare('INSERT INTO t VALUES (3)')->execute());
report('prepared select', $conn->prepare('SELECT * FROM t')->execute());
report('prepared streaming select', $conn->prepare('SELECT * FROM t')->executeStreaming());
report('query streaming select', $conn->queryStreaming('SELECT * FROM t'));
report('pending select', $conn->queryPending('SELECT * FROM t')->await());
report('async prepared update', $conn->prepare('UPDATE t SET i = i + 1')->executeAsync()->await());
report('async query delete', $conn->queryAsync('DELETE FROM t WHERE i > 100')->await());

$conn->query('PREPARE p AS SELECT count(*) FROM t');
report('sql execute', $conn->query('EXECUTE p'));

// A connection with a PHP scalar function runs query() through the pump,
// which executes every statement as a prepared statement.
$pumped = (new DuckDB\Database())->connect();
$pumped->registerScalarFunction('php_identity', ['BIGINT'], 'BIGINT', new Identity());
$pumped->query('CREATE TABLE u(i BIGINT)');
report('pumped insert', $pumped->query('INSERT INTO u SELECT php_identity(42)'));
report('pumped select', $pumped->query('SELECT php_identity(i) FROM u'));
report('pumped multi-statement', $pumped->query('UPDATE u SET i = 7; SELECT i FROM u'));
?>
--EXPECT--
query insert: INSERT
execute insert: INSERT
prepared insert: INSERT
prepared select: SELECT
prepared streaming select: SELECT
query streaming select: SELECT
pending select: SELECT
async prepared update: UPDATE
async query delete: DELETE
sql execute: EXECUTE
pumped insert: INSERT
pumped select: SELECT
pumped multi-statement: SELECT
