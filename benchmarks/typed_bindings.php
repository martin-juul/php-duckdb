<?php
/** Run: php -n -d extension=modules/duckdb.so benchmarks/typed_bindings.php [iterations] */
declare(strict_types=1);

use DuckDB\Database;
use DuckDB\{Decimal, Integer, Struct, Value};

$iterations = max(1, (int) ($argv[1] ?? 1000));
$conn = (new Database(':memory:'))->connect();
$conn->query('SET threads = 1');
$cases = ['ordinary scalar' => [42], 'ordinary batch (8)' => array_fill(0, 8, 42)];
$cases['ordinary composite batch (8)'] = array_fill(0, 8, ['id' => 42, 'tags' => ['a', 'b']]);
if (class_exists(Value::class)) {
    $cases['typed scalar'] = [new Integer('42')];
    $cases['typed scalar batch (8)'] = array_fill(0, 8, new Decimal('12.345', 18, 2));
    $cases['typed composite batch (8)'] = array_fill(0, 8,
        new Struct(['id' => 42, 'tags' => ['a', 'b']], ['id' => Integer::class, 'tags' => 'VARCHAR[]']));
}
foreach ($cases as $label => $params) {
    $stmt = $conn->prepare('SELECT ' . implode(', ', array_fill(0, count($params), '?')));
    for ($i = 0; $i < 20; $i++) { $stmt->execute($params); }
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $stmt->execute($params); }
    printf("%-28s %9.1f us/execution (%d iterations)\n", $label,
        (hrtime(true) - $start) / $iterations / 1000, $iterations);
}
