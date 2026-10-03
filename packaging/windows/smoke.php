<?php
[$script, $minor, $threadSafety, $compiler, $extensionVersion, $duckVersion] = $argv;
if (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== $minor
    || (PHP_ZTS ? 'ts' : 'nts') !== $threadSafety
    || PHP_INT_SIZE !== 8
    || !extension_loaded('duckdb')
    || phpversion('duckdb') !== $extensionVersion
    || DuckDB\version() !== 'v' . $duckVersion) {
    throw new RuntimeException('Package/PHP version or architecture mismatch');
}
ob_start();
phpinfo(INFO_GENERAL);
$info = ob_get_clean();
if (stripos($info, $compiler) === false) {
    throw new RuntimeException('PHP compiler mismatch');
}
$c = (new DuckDB\Database())->connect();
$r = $c->query('SELECT 42 AS x')->fetchRow();
if ($r['x'] !== 42) {
    throw new RuntimeException('DuckDB query smoke failed');
}
printf("smoke OK: duckdb ext %s, libduckdb %s, PHP %s (%s %s x64)\n",
    phpversion('duckdb'), DuckDB\version(), PHP_VERSION, $threadSafety, $compiler);
