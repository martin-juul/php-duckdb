# Configuration

PHP counterpart of the C API's configuration functions (`duckdb_create_config()`,
`duckdb_set_config()`, `duckdb_open_ext()`).

## Passing options

Configuration is a plain PHP array on the `Database` constructor — option name
to scalar value:

```php
use DuckDB\Database;

$db = new Database('/data/app.duckdb', [
    'access_mode'  => 'read_write',
    'threads'      => 4,
    'memory_limit' => '1GB',
]);
```

Rules:

- Keys are DuckDB option names (`string`).
- Values must be scalars: `string`, `int`, `float` or `bool`.
  Booleans are sent as `'true'`/`'false'`, numbers are stringified. Anything
  else (arrays, objects, `null`) throws `\ValueError`.
- Unknown option names fail at open time with a `ConnectionException` whose
  `getErrorType()` is `ErrorType::InvalidConfiguration`.

## Commonly used options

| Option | Example | Effect |
|---|---|---|
| `access_mode` | `'read_only'` | Open without taking the write lock; many readers allowed |
| `threads` | `4` | Worker threads for query execution |
| `memory_limit` | `'1GB'` | Memory budget before spilling to disk |
| `temp_directory` | `'/fast/ssp/tmp'` | Spill location |
| `default_order` | `'DESC'` | Default sort order |
| `enable_object_cache` | `true` | Cache parsed objects |

The full, always-current list lives in the upstream docs:
<https://duckdb.org/docs/stable/configuration/overview>

## Changing options at runtime

Anything DuckDB exposes through SQL can be changed per connection:

```php
$conn->query("SET memory_limit = '2GB'");
$conn->query("SET threads = 8");
$conn->query("INSTALL httpfs; LOAD httpfs;");
```

## Not exposed

The C API's *option enumeration* (`duckdb_config_count()`,
`duckdb_get_config_flag()`) has no PHP wrapper — it exists so C programs can
discover options at runtime; in PHP you consult the upstream documentation
instead. If you need the live list, DuckDB exposes it via SQL:

```php
$conn->query('SELECT name, value, description FROM duckdb_settings()')->fetchAll();
```
