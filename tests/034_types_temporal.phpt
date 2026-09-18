--TEST--
Types: temporal family (DATE/TIME/TIMESTAMP variants, TIMETZ, intervals, infinity)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
use DuckDB\Interval;

$conn = (new DuckDB\Database())->connect();

// DATE and TIMESTAMP become DateTimeImmutable (UTC)
$row = $conn->query("SELECT DATE '2024-02-29' AS d, TIMESTAMP '2024-01-01 00:00:00.5' AS ts")->fetchRow();
echo get_class($row['d']), ' ', $row['d']->format('Y-m-d H:i:s e'), "\n";
echo $row['ts']->format('Y-m-d H:i:s.u e'), "\n";

// All TIMESTAMP precisions
$row = $conn->query("SELECT TIMESTAMP_S '2024-01-01 12:00:00' AS s, TIMESTAMP_MS '2024-01-01 12:00:00.123' AS ms, TIMESTAMP_NS '2024-01-01 12:00:00.123456' AS ns")->fetchRow();
echo $row['s']->format('Y-m-d H:i:s.u'), "\n";
echo $row['ms']->format('Y-m-d H:i:s.u'), "\n";
echo $row['ns']->format('Y-m-d H:i:s.u'), "\n";

// TIMESTAMPTZ normalizes to UTC
$row = $conn->query("SELECT TIMESTAMPTZ '2024-01-01 12:00:00+02' AS tz")->fetchRow();
echo $row['tz']->format('Y-m-d H:i:s e'), "\n";

// TIME / TIMETZ stay strings
$row = $conn->query("SELECT TIME '23:59:59.999999' AS t, TIMETZ '13:14:15+02' AS ttz")->fetchRow();
var_dump($row['t'], $row['ttz']);

// Extreme dates
$row = $conn->query("SELECT DATE '0001-01-01' AS min_d, DATE '9999-12-31' AS max_d")->fetchRow();
echo $row['min_d']->format('Y-m-d'), ' ', $row['max_d']->format('Y-m-d'), "\n";

// Non-finite temporal values are returned as strings
$row = $conn->query("SELECT DATE 'infinity' AS inf_d, TIMESTAMP '-infinity' AS ninf_ts")->fetchRow();
var_dump($row['inf_d'], $row['ninf_ts']);

// INTERVAL decodes into the value object
$row = $conn->query("SELECT INTERVAL '1 year 2 months 3 days 04:05:06.789' AS i")->fetchRow();
echo get_class($row['i']), "\n";
echo $row['i']->getMonths(), ' ', $row['i']->getDays(), ' ', $row['i']->getMicros(), "\n";

$row = $conn->query("SELECT INTERVAL '-3 days' AS i")->fetchRow();
echo $row['i']->getMonths(), ' ', $row['i']->getDays(), ' ', $row['i']->getMicros(), "\n";

// Bound DateTimeInterface is converted to UTC
$row = $conn->execute('SELECT ?::TIMESTAMP AS v', [new DateTimeImmutable('2024-06-01 12:00:00', new DateTimeZone('Europe/Berlin'))])->fetchRow();
echo $row['v']->format('Y-m-d H:i:s e'), "\n";
?>
--EXPECT--
DateTimeImmutable 2024-02-29 00:00:00 UTC
2024-01-01 00:00:00.500000 UTC
2024-01-01 12:00:00.000000
2024-01-01 12:00:00.123000
2024-01-01 12:00:00.123456
2024-01-01 10:00:00 UTC
string(15) "23:59:59.999999"
string(14) "13:14:15+02:00"
0001-01-01 9999-12-31
string(8) "infinity"
string(9) "-infinity"
DuckDB\Interval
14 3 14706789000
0 -3 0
2024-06-01 10:00:00 UTC
