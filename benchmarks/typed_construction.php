<?php
/** Run: php -n -d extension=modules/duckdb.so benchmarks/typed_construction.php [iterations] */
declare(strict_types=1);

use DuckDB\{Decimal, Integer, Struct, Value};

$iterations = max(1, (int) ($argv[1] ?? 100000));
$fields = array_fill_keys(array_map(fn ($i) => 'field_' . $i, range(1, 16)), Integer::class);
$input = array_fill_keys(array_keys($fields), 42);
$declaration = 'STRUCT(' . implode(', ', array_map(fn ($name) => "$name INTEGER", array_keys($fields))) . ')';
$cases = [
    'generic INTEGER' => fn () => new Value('INTEGER', 42),
    'native Integer' => fn () => new Integer(42),
    'native Decimal' => fn () => new Decimal('12.345', 18, 2),
    'INTERVAL declaration' => fn () => new Value('INTERVAL DAY TO SECOND', null),
    'STRUCT declaration (16)' => fn () => new Value($declaration, null),
    'native Struct (16)' => fn () => new Struct($input, $fields),
];
foreach ($cases as $label => $create) {
    for ($i = 0; $i < 100; $i++) { $create(); }
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) { $create(); }
    printf("%-28s %9.3f us/construction (%d iterations)\n", $label,
        (hrtime(true) - $start) / $iterations / 1000, $iterations);
}
