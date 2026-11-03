--TEST--
Geometry CRS metadata: read-side type strings render the CRS like typeof()
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class RecordingScalar implements DuckDB\ScalarFunction, DuckDB\ScalarBinding, DuckDB\ScalarExecutor
{
    public array $argumentTypes = [];

    public function bind(array $argumentTypes): DuckDB\ScalarBinding
    {
        $this->argumentTypes = $argumentTypes;

        return $this;
    }

    public function init(): DuckDB\ScalarExecutor
    {
        return $this;
    }

    public function execute(DuckDB\DataChunk $arguments): array
    {
        return array_fill(0, $arguments->rowCount(), 'ok');
    }
}

$db = new DuckDB\Database();
$conn = $db->connect();
$crs = "GEOMETRY('OGC:CRS84')";

// Result columns match typeof() for top-level and nested geometry types.
$projjson = '{"type":"GeographicCRS","name":"O\'Brien WGS 84","id":{"authority":"EPSG","code":4326}}';
$projjsonType = "GEOMETRY('" . str_replace("'", "''", $projjson) . "')";
$declarations = [
    $crs,
    "{$crs}[]",
    "{$crs}[2]",
    "STRUCT(g $crs)",
    "MAP(VARCHAR, $crs)",
    "UNION(a $crs, b INTEGER)",
    $projjsonType,
    'GEOMETRY',
];
$columns = [];
$typeofs = [];
foreach ($declarations as $i => $declaration) {
    $columns[] = "NULL::$declaration AS c$i";
    $typeofs[] = "typeof(NULL::$declaration) AS c$i";
}
$result = $conn->query('SELECT ' . implode(', ', $columns));
$expected = $conn->query('SELECT ' . implode(', ', $typeofs))->fetchRow();
foreach ($result->columns() as $i => $column) {
    check($column['type'] === $expected["c$i"], "columns() type {$column['type']} differs from typeof() {$expected["c$i"]}");
    check($result->columnType($i) === $column['type'], "columnType($i) differs from columns()");
}
echo $result->columnType(0), "\n";
echo $result->columnType(1), "\n";
echo $result->columnType(3), "\n";
echo $result->columnType(7), "\n";
check(str_contains($result->columnType(6), "O''Brien"), 'PROJJSON CRS quote is not doubled');
echo "result columns match typeof()\n";

// A CRS that DuckDB drops renders as plain GEOMETRY.
$conn->query('SET ignore_unknown_crs = true');
echo $conn->query("SELECT NULL::GEOMETRY('FOO:1') AS g")->columnType(0), "\n";
$conn->query('SET ignore_unknown_crs = false');

// Aliases keep their existing rendering.
echo $conn->query("SELECT '{}'::JSON AS j")->columnType(0), "\n";

// Prepared statements report the CRS for columns and parameters.
$conn->query("CREATE TABLE geo (g $crs)");
echo $conn->prepare('SELECT g FROM geo')->columnType(0), "\n";
echo $conn->prepare('INSERT INTO geo VALUES ($1)')->parameterType(1), "\n";

// Owned vectors and chunks report the CRS.
$vector = $conn->createVector($crs, 1);
echo $vector->type(), "\n";
echo DuckDB\DataChunk::fromVectors(['g' => $vector], 1)->columns()[0]['type'], "\n";

// Arrow-imported chunks report the CRS that the GeoArrow metadata declared.
$arrow = $conn->query("SELECT 'POINT (1 2)'::$crs AS g")->fetchArrowChunk();
$imported = $conn->dataChunkFromArrow($arrow);
$importedType = $imported->columns()[0]['type'];
check(str_starts_with($importedType, "GEOMETRY('") && str_contains($importedType, 'CRS84'), "Arrow import lost its CRS: $importedType");
check($imported->vector(0)->type() === $importedType, 'Imported vector type differs from chunk column type');
echo "arrow import keeps CRS\n";

// Vector error messages name CRS-qualified types; CRS-only differences stay compatible.
$list = $conn->createVector("{$crs}[]", 1);
$array = $conn->createVector("{$crs}[2]", 1);
try {
    $list->copyFrom($array);
    echo "copy between LIST and ARRAY accepted\n";
} catch (TypeError $error) {
    echo $error->getMessage(), "\n";
}
$plain = $conn->createVector('GEOMETRY', 1);
$plain->copyFrom($vector);
echo "CRS-only copy accepted\n";

// Scalar bind argument types keep including the CRS.
$scalar = new RecordingScalar();
$conn->registerScalarFunction('php_geo', [$crs], 'VARCHAR', $scalar);
check($conn->query("SELECT php_geo('POINT (1 2)'::$crs) AS v")->fetchColumn() === 'ok', 'Scalar call failed');
echo $scalar->argumentTypes[0], "\n";
?>
--EXPECT--
GEOMETRY('OGC:CRS84')
GEOMETRY('OGC:CRS84')[]
STRUCT(g GEOMETRY('OGC:CRS84'))
GEOMETRY
result columns match typeof()
GEOMETRY
VARCHAR
GEOMETRY('OGC:CRS84')
GEOMETRY('OGC:CRS84')
GEOMETRY('OGC:CRS84')
GEOMETRY('OGC:CRS84')
arrow import keeps CRS
DuckDB\Vector::copyFrom(): Argument #1 ($source) must have type GEOMETRY('OGC:CRS84')[], GEOMETRY('OGC:CRS84')[2] given
CRS-only copy accepted
GEOMETRY('OGC:CRS84')
