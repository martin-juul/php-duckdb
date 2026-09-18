--TEST--
Hardening: value conversion nesting is depth-limited (no C stack overflow)
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php
$conn = (new DuckDB\Database())->connect();

// legitimate nesting up to the limit works
$val = 1;
for ($i = 0; $i < 512; $i++) {
    $val = [$val];
}
echo "bind 512-deep list: ", $conn->execute('SELECT ? AS v', [$val])->fetchAll()[0]['v'] === null ? 'null' : 'ok', "\n";

// past the limit: clean ValueError instead of a stack overflow crash
$val = 1;
for ($i = 0; $i < 513; $i++) {
    $val = [$val];
}
try {
    $conn->execute('SELECT ? AS v', [$val]);
    echo "bind 513-deep list: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "bind 513-deep list: ValueError\n";
}

// same via the appender path
$conn->query('CREATE TABLE depth_t (v INTEGER[])');
$appender = $conn->appender('depth_t');
$deep = 1;
for ($i = 0; $i < 600; $i++) {
    $deep = [$deep];
}
try {
    $appender->appendRow([$deep]);
    echo "appender 600-deep: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "appender 600-deep: ValueError\n";
}

// decode side: a deeply nested result is rejected cleanly
$literal = str_repeat('[', 600) . '1' . str_repeat(']', 600);
try {
    $conn->query("SELECT $literal AS v")->fetchAll();
    echo "decode 600-deep: NOT REJECTED\n";
} catch (DuckDB\Exception $e) {
    echo "decode 600-deep: DuckDB\\Exception\n";
}

// deep structs bind-side
$val = 1;
for ($i = 0; $i < 600; $i++) {
    $val = ['k' => $val];
}
try {
    $conn->execute('SELECT ? AS v', [$val]);
    echo "struct 600-deep: NOT REJECTED\n";
} catch (\ValueError $e) {
    echo "struct 600-deep: ValueError\n";
}
echo "done\n";
?>
--EXPECT--
bind 512-deep list: ok
bind 513-deep list: ValueError
appender 600-deep: ValueError
decode 600-deep: DuckDB\Exception
struct 600-deep: ValueError
done
