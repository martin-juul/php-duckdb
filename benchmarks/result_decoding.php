<?php
/** Run: php -n -d extension=modules/duckdb.so benchmarks/result_decoding.php [rows] */
declare(strict_types=1);

use DuckDB\{Database, FetchMode};

$argument = $argv[1] ?? '1000';
if (count($argv) > 2 || !preg_match('/^[0-9]+$/D', $argument) || (int) $argument < 1 || (int) $argument > 5000) {
    fwrite(STDERR, "Usage: result_decoding.php [rows: 1..5000, default 1000]\n");
    exit(1);
}
$rows = (int) $argument;
$conn = (new Database(':memory:', ['threads' => 1]))->connect();
$version = $conn->query('SELECT version()')->fetchColumn();
$short = str_repeat('9', 39);
$long = str_repeat('9', 1000);
$nested = '{"id":42,"tags":["alpha","beta"],"details":{"active":true,"values":[1,2,null]}}';
$cases = [
    'VARCHAR (39 bytes)' => ["'$short'::VARCHAR", $short],
    'BIGNUM (39 digits)' => ["'$short'::BIGNUM", $short],
    'BIGNUM (1000 digits)' => ["'$long'::BIGNUM", $long],
    'VARIANT scalar string' => ["'hello'::VARCHAR::VARIANT", '"hello"'],
    'VARIANT nested JSON' => ["'$nested'::JSON::VARIANT", $nested],
];

printf("PHP %s %s; DuckDB %s; %s %s; threads=1; %d rows/run\n",
    PHP_VERSION, PHP_ZTS ? 'ZTS' : 'NTS', $version, PHP_OS_FAMILY, php_uname('m'), $rows);
echo "One warmup, median of three measured runs; buffered execute excluded, fetch/check included.\n";
foreach ($cases as $label => [$expression, $expected]) {
    $stmt = $conn->prepare("SELECT $expression AS value FROM range($rows)");
    $durations = [];
    for ($run = 0; $run < 4; ++$run) {
        // execute() materializes the result, including SQL casts, before timing.
        $result = $stmt->execute();
        $count = 0;
        $bytes = 0;
        $start = hrtime(true);
        while (($row = $result->fetchRow(FetchMode::Num)) !== null) {
            if ($row[0] !== $expected) {
                throw new RuntimeException("Unexpected decoded value for $label at row $count");
            }
            ++$count;
            $bytes += strlen($row[0]);
        }
        $seconds = (hrtime(true) - $start) / 1e9;
        if ($count !== $rows || $bytes !== $rows * strlen($expected)) {
            throw new RuntimeException("Incomplete result for $label");
        }
        unset($result);
        if ($run > 0) {
            $durations[] = $seconds;
        }
    }
    sort($durations);
    $median = $durations[1];
    printf("%-26s %10.3f us/row %12.0f rows/s (%d bytes/run)\n",
        $label, $median * 1e6 / $rows, $rows / $median, $bytes);
}
