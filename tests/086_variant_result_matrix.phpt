--TEST--
Result: VARIANT JSON rendering for all payload tags, Parquet round trips and persisted multi-chunk results
--SKIPIF--
<?php require_once __DIR__ . '/skipif.inc'; ?>
--FILE--
<?php

$databasePath = sys_get_temp_dir() . '/duckdb-variant-matrix-' . bin2hex(random_bytes(8)) . '.db';

try {
    $database = new DuckDB\Database($databasePath, ['threads' => '1']);
    $conn = $database->connect();
    $conn->query("SET TimeZone = 'UTC'");
    // Each of the 35 VARIANT payload tags has a direct case. SQL JSON casts are
    // the formatting oracle; every assertion reads the uncast VARIANT into PHP.
    $cases = [
        'variant null' => "'null'::JSON::VARIANT",
        'true' => 'TRUE::VARIANT',
        'false' => 'FALSE::VARIANT',
        'int8' => '(-128)::TINYINT::VARIANT',
        'int16' => '(-32768)::SMALLINT::VARIANT',
        'int32' => '(-2147483648)::INTEGER::VARIANT',
        'int64' => "'-9223372036854775808'::BIGINT::VARIANT",
        'int128' => "'-170141183460469231731687303715884105728'::HUGEINT::VARIANT",
        'uint8' => '255::UTINYINT::VARIANT',
        'uint16' => '65535::USMALLINT::VARIANT',
        'uint32' => '4294967295::UINTEGER::VARIANT',
        'uint64' => "'18446744073709551615'::UBIGINT::VARIANT",
        'uint128' => "'340282366920938463463374607431768211455'::UHUGEINT::VARIANT",
        'float' => '0.1::FLOAT::VARIANT',
        'double' => '0.1::DOUBLE::VARIANT',
        'decimal' => "'-123456789012345678901234567890123456.70'::DECIMAL(38,2)::VARIANT",
        'varchar' => "('quote\" slash\\ newline' || chr(10) || 'nul' || chr(0) || '🦆')::VARIANT",
        'blob' => "from_hex('00ff61005c')::VARIANT",
        'uuid' => "'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11'::UUID::VARIANT",
        'date' => "DATE '2024-02-29'::VARIANT",
        'time micros' => "TIME '12:34:56.123456'::VARIANT",
        'time nanos' => "'12:34:56.123456789'::TIME_NS::VARIANT",
        'timestamp seconds' => "TIMESTAMP_S '2024-01-01 12:00:00'::VARIANT",
        'timestamp millis' => "TIMESTAMP_MS '2024-01-01 12:00:00.123'::VARIANT",
        'timestamp micros' => "TIMESTAMP '2024-01-01 12:00:00.123456'::VARIANT",
        'timestamp nanos' => "TIMESTAMP_NS '2024-01-01 12:00:00.123456789'::VARIANT",
        'time timezone' => "TIMETZ '12:34:56.123456-02:03:04'::VARIANT",
        'timestamp timezone' => "TIMESTAMPTZ '2024-01-01 12:00:00.123456+02'::VARIANT",
        'timestamp nanos timezone' => "TIMESTAMPTZ_NS '2024-01-01 12:00:00.123456789+02'::VARIANT",
        'interval' => "INTERVAL '1 year 2 months 3 days 04:05:06.789'::VARIANT",
        'object' => "{'a': 1::UTINYINT, 'b': 'two', 'n': NULL, 'list': [1, 2]}::VARIANT",
        'array' => "'[1,\"two\",null,{\"nested\":true},[]]'::JSON::VARIANT",
        'bignum' => "'123456789012345678901234567890123456789012345678901234567890'::BIGNUM::VARIANT",
        'bitstring' => "'00000000101'::BIT::VARIANT",
        'geometry' => "'POINT (1 2)'::GEOMETRY::VARIANT",
        'sql null' => 'NULL::VARIANT',
        'empty object' => "'{}'::JSON::VARIANT",
        'empty array' => "'[]'::JSON::VARIANT",
        'empty varchar' => "''::VARCHAR::VARIANT",
        'empty blob' => "''::BLOB::VARIANT",
        'bignum negative' => "'-12345678901234567890123456789012345678901234567890'::BIGNUM::VARIANT",
        'decimal int16' => "'1.20'::DECIMAL(4,2)::VARIANT",
        'decimal int32' => "'-1234.50'::DECIMAL(9,2)::VARIANT",
        'decimal int64' => "'1234567890123456.70'::DECIMAL(18,2)::VARIANT",
        'decimal zero' => '0::DECIMAL(18,3)::VARIANT',
        'decimal fraction only' => "'0.5'::DECIMAL(1,1)::VARIANT",
        'decimal negative fraction only' => "'-0.50'::DECIMAL(2,2)::VARIANT",
        'object unsorted keys' => "'{\"zeta\":1,\"alpha\":{\"y\":2,\"x\":3},\"Alpha\":4,\"_\":5}'::JSON::VARIANT",
        'unicode key and control characters' => <<<'SQL'
'{"a\u0000b":"quotes \" slash \\ newline\n tab\t","🦆":"héllo"}'::JSON::VARIANT
SQL,
        'date positive infinity' => "DATE 'infinity'::VARIANT",
        'date negative infinity' => "DATE '-infinity'::VARIANT",
        'timestamp positive infinity' => "TIMESTAMP 'infinity'::VARIANT",
        'timestamp negative infinity' => "TIMESTAMP '-infinity'::VARIANT",
        'timestamp seconds infinity' => "TIMESTAMP_S 'infinity'::VARIANT",
        'timestamp millis infinity' => "TIMESTAMP_MS '-infinity'::VARIANT",
        'timestamp nanos before epoch' => "TIMESTAMP_NS '1969-12-31 23:59:59.999999999'::VARIANT",
        'timestamp nanos timezone before epoch' => "TIMESTAMPTZ_NS '1969-12-31 23:59:59.999999999+00'::VARIANT",
    ];
    foreach (['FLOAT', 'DOUBLE'] as $type) {
        foreach (['NaN', 'infinity', '-infinity', '-0.0', '1e-7', '1e-6', '1e20', '1e21',
            '1.2345678901234567', '2.2250738585072014e-308', '1.7976931348623157e308'] as $number) {
            // Restrict FLOAT magnitude cases to its representable range.
            if ($type === 'FLOAT' && in_array($number, ['2.2250738585072014e-308', '1.7976931348623157e308'], true)) {
                continue;
            }
            $cases["$type $number"] = "'$number'::$type::VARIANT";
        }
    }
    $geometries = [
        'POINT EMPTY', 'POINT Z (1 2 3)', 'POINT M (1 2 4)', 'POINT ZM (1 2 3 4)',
        'LINESTRING (0 0, 1 1)', 'LINESTRING Z (0 0 1, 1 1 2)',
        'POLYGON ((0 0, 2 0, 2 2, 0 0))', 'POLYGON EMPTY',
        'MULTIPOINT ((0 0), (1 1))', 'MULTIPOINT Z ((0 0 1), (1 1 2))',
        'MULTILINESTRING ((0 0, 1 1), (2 2, 3 3))',
        'MULTIPOLYGON (((0 0, 2 0, 2 2, 0 0)))',
        'GEOMETRYCOLLECTION (POINT (1 2), LINESTRING (0 0, 1 1))',
        'GEOMETRYCOLLECTION EMPTY', 'POINT (0.00001 0.0001)', 'POINT (1e15 1e16)',
    ];
    foreach ($geometries as $wkt) {
        $cases['geometry ' . $wkt] = "'$wkt'::GEOMETRY::VARIANT";
    }
    $labels = array_keys($cases);
    $selects = [];
    foreach (array_values($cases) as $id => $expression) {
        $selects[] = "SELECT $id AS id, $expression AS v";
    }
    $conn->query('CREATE TABLE variants AS ' . implode(' UNION ALL ', $selects));

    $failures = [];
    function verify_variant_rows(array $rows, array $expectedIds, array $labels, string $context, array &$failures): void {
        if (array_column($rows, 'id') !== $expectedIds) {
            throw new RuntimeException("Wrong rows: $context");
        }
        foreach ($rows as $row) {
            // DuckDB's VARIANT-to-JSON cast renders SQL NULL as JSON text null.
            // PHP result decoding must retain SQL NULL as PHP null instead.
            $expected = $row['sql_null'] ? null : $row['oracle'];
            if ($row['v'] !== $expected) {
                $failures[] = "$context/{$labels[$row['id']]}: actual=" . var_export($row['v'], true)
                    . ' expected=' . var_export($expected, true);
            }
        }
    }
    $sql = 'SELECT id, v, CAST(v AS JSON)::VARCHAR AS oracle, v IS NULL AS sql_null FROM variants WHERE id >= ? ORDER BY id';
    $literalSql = str_replace('id >= ?', 'id >= 0', $sql);
    $statement = $conn->prepare($sql);
    $paths = [
        'query' => static fn() => $conn->query($literalSql),
        'streaming' => static fn() => $conn->queryStreaming($literalSql),
        'execute' => static fn() => $conn->execute($sql, [0]),
        'prepared' => static fn() => $statement->execute([0]),
        'prepared streaming' => static fn() => $statement->executeStreaming([0]),
        'prepared async' => static fn() => $statement->executeAsync([0])->await(),
    ];
    foreach ($paths as $path => $run) {
        foreach (['fetchRow', 'fetchAll', 'iterator'] as $fetch) {
            $result = $run();
            if ($fetch === 'fetchRow') {
                $rows = [];
                while (($row = $result->fetchRow()) !== null) {
                    $rows[] = $row;
                }
            } else {
                $rows = $fetch === 'fetchAll' ? $result->fetchAll() : iterator_to_array($result, false);
            }
            verify_variant_rows($rows, range(0, count($cases) - 1), $labels, "$path/$fetch", $failures);
            unset($result);
        }
        echo $path, ": all payloads match JSON cast across fetch paths\n";
    }
    $nested = new DuckDB\Variant([
        'uint8' => new DuckDB\UTinyInt(255),
        'decimal' => new DuckDB\Decimal('1.230', precision: 18, scale: 3),
        'huge' => new DuckDB\UHugeInt('340282366920938463463374607431768211455'),
        'nul' => new DuckDB\Varchar("a\0b"),
        'blob' => new DuckDB\Blob("\0\xff"),
        'array' => [new DuckDB\Integer(1), new DuckDB\Integer(null)],
        'object' => ['flag' => new DuckDB\Boolean(true)],
    ]);
    $row = $conn->execute('WITH input AS (SELECT ? AS v) SELECT v, CAST(v AS JSON)::VARCHAR AS oracle FROM input', [$nested])->fetchRow();
    if ($row['v'] !== $row['oracle']) {
        throw new RuntimeException('Nested typed wrappers lost JSON information');
    }
    echo "nested typed wrappers: exact JSON\n";

    // A filtered projection exercises dictionary/shredded vectors after storage.
    $filtered = $conn->query('SELECT id, v, CAST(v AS JSON)::VARCHAR AS oracle, v IS NULL AS sql_null FROM variants WHERE id % 3 = 1 ORDER BY id DESC')->fetchAll();
    $ids = array_reverse(array_values(array_filter(range(0, count($cases) - 1), static fn(int $id) => $id % 3 === 1)));
    verify_variant_rows($filtered, $ids, $labels, 'filtered persisted values', $failures);
    echo "filtered persisted payloads: exact JSON\n";

    // DuckDB's own Parquet writer and reader re-encode VARIANT. Parquet VARIANT
    // has no HUGEINT, UHUGEINT, UBIGINT maximum, TIME_NS, TIMESTAMP_S/MS, TIMETZ,
    // INTERVAL, BIGNUM, BIT or GEOMETRY payload, and accepts TIMESTAMPTZ_NS only
    // in a uniformly typed (shredded) column, so that payload gets its own file.
    $parquetLabels = [
        'variant null', 'true', 'false', 'int8', 'int16', 'int32', 'int64', 'uint8', 'uint16', 'uint32',
        'float', 'double', 'decimal', 'varchar', 'blob', 'uuid', 'date', 'time micros', 'timestamp micros',
        'timestamp nanos', 'timestamp timezone', 'object', 'array', 'sql null', 'empty object', 'empty array',
        'empty varchar', 'empty blob', 'decimal int16', 'decimal int32', 'decimal int64', 'decimal zero',
        'decimal fraction only', 'decimal negative fraction only', 'object unsorted keys',
        'unicode key and control characters',
    ];
    $parquetIds = array_map(static fn(string $label) => array_search($label, $labels, true), $parquetLabels);
    sort($parquetIds);
    $parquetPath = sys_get_temp_dir() . '/duckdb-variant-matrix-' . bin2hex(random_bytes(8)) . '.parquet';
    $parquetFiles = [$parquetPath];
    $conn->query("COPY (SELECT id, v FROM variants WHERE id IN (" . implode(', ', $parquetIds) . ")) TO '$parquetPath' (FORMAT parquet)");
    $sqlValues = array_column($conn->query($literalSql)->fetchAll(), 'v', 'id');
    $parquetSql = "SELECT id, v, CAST(v AS JSON)::VARCHAR AS oracle, v IS NULL AS sql_null FROM read_parquet('$parquetPath') ORDER BY id";
    $parquetRows = $conn->query($parquetSql)->fetchAll();
    verify_variant_rows($parquetRows, $parquetIds, $labels, 'parquet', $failures);
    foreach ($parquetRows as $row) {
        if ($row['v'] !== $sqlValues[$row['id']]) {
            $failures[] = "parquet/{$labels[$row['id']]}: Parquet value differs from SQL value";
        }
    }
    $nanosId = array_search('timestamp nanos timezone', $labels, true);
    $nanosPath = sys_get_temp_dir() . '/duckdb-variant-matrix-' . bin2hex(random_bytes(8)) . '.parquet';
    $parquetFiles[] = $nanosPath;
    $conn->query("COPY (SELECT id, v FROM variants WHERE id = $nanosId) TO '$nanosPath' (FORMAT parquet)");
    $nanosRows = $conn->query(str_replace($parquetPath, $nanosPath, $parquetSql))->fetchAll();
    verify_variant_rows($nanosRows, [$nanosId], $labels, 'parquet shredded', $failures);
    if ($nanosRows[0]['v'] !== '"2024-01-01 10:00:00.123456789+00"') {
        $failures[] = 'parquet shredded/timestamp nanos timezone: ' . var_export($nanosRows[0]['v'], true);
    }
    echo "parquet written by DuckDB: exact JSON, same as SQL values\n";

    function verify_variant_chunks(DuckDB\Connection $conn, string $source, string $context): void {
        foreach (['buffered' => false, 'streaming' => true] as $mode => $streaming) {
            $chunkSql = "SELECT id, v, CAST(v AS JSON)::VARCHAR AS oracle FROM ($source) ORDER BY id";
            $result = $streaming ? $conn->queryStreaming($chunkSql) : $conn->query($chunkSql);
            $count = 0;
            while (($row = $result->fetchRow()) !== null) {
                if ($row['id'] !== $count || $row['v'] !== $row['oracle']) {
                    throw new RuntimeException("VARIANT chunk decoding mismatch: $context/$mode/$count");
                }
                $count++;
            }
            if ($count !== 4105) {
                throw new RuntimeException("VARIANT chunk result truncated: $context/$mode");
            }
            unset($result);
            echo "$context $mode: 4105 rows cross chunk boundaries\n";
        }
    }
    $chunkSource = "SELECT i AS id, {'n': i, 'nil': NULL, 'text': 'a' || chr(0) || 'b', 'list': [i, i + 1]}::VARIANT AS v FROM range(4105) t(i)";
    verify_variant_chunks($conn, $chunkSource, 'query');
    $conn->query("CREATE TABLE chunk_variants AS $chunkSource");
    $conn->query('CHECKPOINT');
    unset($paths, $run, $statement, $conn, $database);
    $database = new DuckDB\Database($databasePath, ['threads' => '1']);
    $conn = $database->connect();
    $rows = $conn->query($literalSql)->fetchAll();
    verify_variant_rows($rows, range(0, count($cases) - 1), $labels, 'reopened database', $failures);
    echo "reopened database: exact JSON\n";
    verify_variant_chunks($conn, 'SELECT id, v FROM chunk_variants', 'reopened checkpoint');
    if ($failures !== []) {
        throw new RuntimeException(implode("\n", $failures));
    }
} finally {
    unset($paths, $run, $statement, $result, $conn, $database);
    @unlink($databasePath);
    @unlink($databasePath . '.wal');
    foreach ($parquetFiles ?? [] as $file) {
        @unlink($file);
    }
}
?>
--EXPECT--
query: all payloads match JSON cast across fetch paths
streaming: all payloads match JSON cast across fetch paths
execute: all payloads match JSON cast across fetch paths
prepared: all payloads match JSON cast across fetch paths
prepared streaming: all payloads match JSON cast across fetch paths
prepared async: all payloads match JSON cast across fetch paths
nested typed wrappers: exact JSON
filtered persisted payloads: exact JSON
parquet written by DuckDB: exact JSON, same as SQL values
query buffered: 4105 rows cross chunk boundaries
query streaming: 4105 rows cross chunk boundaries
reopened database: exact JSON
reopened checkpoint buffered: 4105 rows cross chunk boundaries
reopened checkpoint streaming: 4105 rows cross chunk boundaries
