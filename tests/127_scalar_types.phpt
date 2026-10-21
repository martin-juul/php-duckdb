--TEST--
Scalar callbacks: nested types, DECIMAL, named ENUM and zero-argument output vectors
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/scalar.inc';

final class TypedScalar implements DuckDB\ScalarFunction, DuckDB\ScalarFunctionBinding, DuckDB\ScalarFunctionExecutor
{
    public array $binds = [];
    public int $rows = 0;
    public array $columnCounts = [];

    public function __construct(private ?DuckDB\Connection $writer = null)
    {
    }

    public function bind(array $argumentTypes, array $constantArguments): DuckDB\ScalarFunctionBinding
    {
        $this->binds[] = [$argumentTypes, $constantArguments];

        return $this;
    }

    public function init(): DuckDB\ScalarFunctionExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): DuckDB\Vector
    {
        $this->rows += $arguments->rowCount();
        $this->columnCounts[] = $arguments->columnCount();
        if ($this->writer === null) {
            return $arguments->vector(0);
        }

        $vector = $this->writer->createVector('VARCHAR', $arguments->rowCount());
        $vector->setValues($this->writer, array_fill(0, $arguments->rowCount(), 'generated'));

        return $vector;
    }
}

$db = new DuckDB\Database();
$conn = $db->connect();
$conn->query('SET threads = 2');
$conn->query("CREATE TYPE scalar_mood AS ENUM ('calm', 'busy')");
$cases = [
    'list' => [
        'INTEGER[]',
        '([1, NULL, 3]::INTEGER[]), ([]::INTEGER[]), (NULL::INTEGER[])',
        [[1, null, 3], [], null],
        '[7, NULL]::INTEGER[]',
        [7, null],
    ],
    'struct' => [
        'STRUCT(items INTEGER[], label VARCHAR)',
        "({'items': [1, NULL], 'label': 'x'}::STRUCT(items INTEGER[], label VARCHAR)), "
            . "({'items': [], 'label': NULL}::STRUCT(items INTEGER[], label VARCHAR)), "
            . '(NULL::STRUCT(items INTEGER[], label VARCHAR))',
        [['items' => [1, null], 'label' => 'x'], ['items' => [], 'label' => null], null],
        "{'items': [9], 'label': NULL}::STRUCT(items INTEGER[], label VARCHAR)",
        ['items' => [9], 'label' => null],
    ],
    'decimal' => [
        new DuckDB\Decimal(null, 18, 3),
        '(12.340::DECIMAL(18,3)), (-0.125::DECIMAL(18,3)), (NULL::DECIMAL(18,3))',
        ['12.340', '-0.125', null],
        '7.125::DECIMAL(18,3)',
        '7.125',
    ],
    'enum' => [
        'scalar_mood',
        "('calm'::scalar_mood), ('busy'::scalar_mood), (NULL::scalar_mood)",
        ['calm', 'busy', null],
        "'busy'::scalar_mood",
        'busy',
    ],
];
foreach ($cases as $name => [$type, $inputs, $expected, $constant, $constantValue]) {
    $function = new TypedScalar();
    $sqlName = 'php_identity_' . $name;
    $conn->registerScalarFunction($sqlName, [$type], $type, $function);
    $rows = $conn->query("SELECT $sqlName(v) AS result FROM (VALUES $inputs) t(v)")->fetchAll();
    scalar_check(array_column($rows, 'result') === $expected, "$name output: " . json_encode($rows));
    scalar_check($function->rows === 3, "$name receives NULL rows");
    scalar_check($function->binds[0][1] === [], "$name nonconstant metadata");

    $rows = $conn->query("SELECT $sqlName($constant) AS result")->fetchAll();
    scalar_check($rows === [['result' => $constantValue]], "$name constant output: " . json_encode($rows));
    $last = $function->binds[array_key_last($function->binds)];
    scalar_check($last[1] === [0 => $constantValue], "$name constant metadata: " . json_encode($last));
    echo "$name identity and constant\n";
}

$function = new TypedScalar($db->connect());
$conn->registerScalarFunction('php_generate', [], 'VARCHAR', $function);
$rows = $conn->query('SELECT php_generate() AS result FROM range(5000)')->fetchAll();
scalar_check(array_column($rows, 'result') === array_fill(0, 5000, 'generated'), 'Zero-argument output');
scalar_check($function->rows === 5000, 'Zero-argument function is volatile');
scalar_check($function->binds[0] === [[], []], 'Zero-argument bind metadata');
scalar_check(array_unique($function->columnCounts) === [0], 'Zero-argument chunks have no columns');
echo "zero arguments and separate output connection\n";
?>
--EXPECT--
list identity and constant
struct identity and constant
decimal identity and constant
enum identity and constant
zero arguments and separate output connection
