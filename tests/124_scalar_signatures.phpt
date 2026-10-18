--TEST--
PHP scalar functions: rejected signature changes preserve existing registrations on both connections
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new RuntimeException('Expected registration to fail');
}

final class SignatureScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public function __construct(private int $value)
    {
    }
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
        return array_fill(0, $arguments->rowCount(), $this->value);
    }
}

$db = new DuckDB\Database();
$first = $db->connect();
$second = $db->connect();
$first->registerScalarFunction('php_signature', ['BIGINT'], 'BIGINT', new SignatureScalar(1));
$prepared = $first->prepare('SELECT php_signature(7::BIGINT)');
$changes = [
    ['parameter type', ['VARCHAR'], 'BIGINT'],
    ['parameter count', ['BIGINT', 'BIGINT'], 'BIGINT'],
    ['return type', ['BIGINT'], 'VARCHAR'],
];
foreach ($changes as [$label, $parameters, $returns]) {
    foreach ([$first, $second] as $connection) {
        $error = fails(fn() => $connection->registerScalarFunction('PHP_SIGNATURE', $parameters, $returns,
            new SignatureScalar(2)));
        check($error instanceof DuckDB\Exception, "$label change rejected");
    }
    check($prepared->execute()->fetchColumn() === 1, 'Rejected registration preserves prepared statement');
    check($first->query('SELECT php_signature(3::BIGINT)')->fetchColumn() === 1,
        'Rejected registration preserves original handler');
    echo "$label\n";
}
$second->registerScalarFunction('php_signature', ['BIGINT'], 'BIGINT', new SignatureScalar(9));
check($first->query('SELECT php_signature(1::BIGINT)')->fetchColumn() === 1, 'First handler remains local');
check($second->query('SELECT php_signature(1::BIGINT)')->fetchColumn() === 9, 'Second handler remains local');
echo "same signature, independent handlers\n";
?>
--EXPECT--
parameter type
parameter count
return type
same signature, independent handlers
