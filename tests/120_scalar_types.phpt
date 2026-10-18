--TEST--
PHP scalar functions: registered return types, typed wrappers, nested inputs and NULL output
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

final class TypedIdentity implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public array $seenTypes = [];
    public function __construct(private string $type)
    {
    }
    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        $this->seenTypes[] = $argumentTypes;
        return $this;
    }
    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }
    public function execute(DuckDB\DataChunk $arguments): array
    {
        $out = [];
        foreach ($arguments->toRows(DuckDB\FetchMode::Num) as [$value]) {
            $out[] = $value === null ? null : new DuckDB\Value($this->type, $value);
        }
        return $out;
    }
}

$conn = (new DuckDB\Database())->connect();
$cases = [
    ['integer', 'INTEGER', DuckDB\Integer::class, '42::INTEGER', 42],
    ['decimal', 'DECIMAL(12,2)', new DuckDB\Decimal('0.00', 12, 2), '12.34::DECIMAL(12,2)', '12.34'],
    ['blob', 'BLOB', DuckDB\Blob::class, "'duck'::BLOB", 'duck'],
    ['list', 'BIGINT[]', 'BIGINT[]', '[1::BIGINT, NULL, 3::BIGINT]', [1, null, 3]],
    ['struct', 'STRUCT(n BIGINT, s VARCHAR)', 'STRUCT(n BIGINT, s VARCHAR)',
        "{'n': 7::BIGINT, 's': 'duck'}", ['n' => 7, 's' => 'duck']],
];
foreach ($cases as [$name, $type, $declaration, $sql, $expected]) {
    $handler = new TypedIdentity($type);
    $conn->registerScalarFunction('php_type_' . $name, [$declaration], $declaration, $handler);
    $result = $conn->query("SELECT php_type_$name($sql) AS v");
    check($result->columnType(0) === $type, "$name registered output type");
    check($result->fetchColumn() === $expected, "$name round trip");
    $boundType = $name === 'struct' ? 'STRUCT("n" BIGINT, "s" VARCHAR)' : $type;
    check($handler->seenTypes !== [] && $handler->seenTypes[0] === [$boundType], "$name resolved input type");
    check($conn->query("SELECT php_type_$name(NULL::$type)")->fetchColumn() === null, "$name NULL output");
    echo "$name\n";
}
?>
--EXPECT--
integer
decimal
blob
list
struct
