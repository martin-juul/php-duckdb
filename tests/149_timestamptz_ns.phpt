--TEST--
Types: TIMESTAMPTZ_NS decodes like TIMESTAMPTZ and reports its type name
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

$sql = "SELECT
    '2026-11-03 12:00:00.123456789+00'::TIMESTAMPTZ_NS AS t,
    '2026-11-03 12:00:00.5+02'::TIMESTAMPTZ_NS AS offset_t,
    NULL::TIMESTAMPTZ_NS AS n,
    ['2026-11-03 12:00:00.123456789+00'::TIMESTAMPTZ_NS, NULL] AS l";

function show(string $label, array $row): void {
    echo $label, ': ',
        get_class($row['t']), ' ', $row['t']->format('Y-m-d H:i:s.u e'), ' | ',
        $row['offset_t']->format('Y-m-d H:i:s.u e'), ' | ',
        var_export($row['n'], true), ' | ',
        $row['l'][0]->format('Y-m-d H:i:s.u e'), ' ', var_export($row['l'][1], true), "\n";
}

$buffered = $conn->query($sql);
foreach ($buffered->columns() as $column) {
    echo $column['name'], ' => ', $column['type'], "\n";
}
show('buffered', $buffered->fetchRow());

$streaming = $conn->queryStreaming($sql);
show('streaming', $streaming->fetchRow());
unset($streaming);
?>
--EXPECT--
t => TIMESTAMPTZ_NS
offset_t => TIMESTAMPTZ_NS
n => TIMESTAMPTZ_NS
l => TIMESTAMPTZ_NS[]
buffered: DateTimeImmutable 2026-11-03 12:00:00.123456 UTC | 2026-11-03 10:00:00.500000 UTC | NULL | 2026-11-03 12:00:00.123456 UTC NULL
streaming: DateTimeImmutable 2026-11-03 12:00:00.123456 UTC | 2026-11-03 10:00:00.500000 UTC | NULL | 2026-11-03 12:00:00.123456 UTC NULL
