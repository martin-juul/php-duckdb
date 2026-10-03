--TEST--
getFd returns independently owned duplicate completion descriptors
--SKIPIF--
<?php
require_once __DIR__ . '/skipif.inc';
if (!extension_loaded('FFI')) {
    die('skip FFI required to close a native descriptor');
}
try {
    FFI::cdef(PHP_OS_FAMILY === 'Windows' ? 'int closesocket(uintptr_t s);' : 'int close(int fd);',
        PHP_OS_FAMILY === 'Windows' ? 'Ws2_32.dll' : null);
} catch (Throwable $e) {
    die('skip FFI unavailable: ' . $e->getMessage());
}
?>
--FILE--
<?php
$native = FFI::cdef(PHP_OS_FAMILY === 'Windows' ? 'int closesocket(uintptr_t s);' : 'int close(int fd);',
    PHP_OS_FAMILY === 'Windows' ? 'Ws2_32.dll' : null);
$c = (new DuckDB\Database())->connect();
$p = $c->queryAsync('SELECT 42 AS n');
$a = $p->getFd();
$b = $p->getFd();
var_dump($a >= 0 && $b >= 0 && $a !== $b);
foreach ([$a, $b] as $fd) {
    var_dump(PHP_OS_FAMILY === 'Windows' ? $native->closesocket($fd) : $native->close($fd));
}
// Closing duplicate handles must not close the PendingQuery's endpoint.
$stream = $p->getStream();
$read = [$stream];
$write = $except = null;
var_dump(stream_select($read, $write, $except, 10));
var_dump(fread($stream, 1) === "\x01");
var_dump($p->await()->fetchRow()['n']);
fclose($stream);
?>
--EXPECT--
bool(true)
int(0)
int(0)
int(1)
bool(true)
int(42)
