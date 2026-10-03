# Configuration

Set database options when you open a database. The PHP interface wraps the C
API's configuration functions (`duckdb_create_config()`, `duckdb_set_config()`,
`duckdb_open_ext()`).

## Passing options

Pass a configuration array to the `Database` constructor, with each option
name mapped to a scalar value:

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
- Unknown option names fail at open time with a `DuckDB\Exception` whose
  `getErrorType()` is `ErrorType::InvalidConfiguration`.

## Commonly used options

| Option | Example | Effect |
| --- | --- | --- |
| `access_mode` | `'read_only'` | Open without taking the write lock; many readers allowed |
| `threads` | `4` | Worker threads for query execution |
| `memory_limit` | `'1GB'` | Memory budget before spilling to disk |
| `temp_directory` | `'/fast/ssp/tmp'` | Spill location |
| `default_order` | `'DESC'` | Default sort order |
| `enable_object_cache` | `true` | Cache parsed objects |
| `enable_optimistic_write` | `false` | DuckDB 1.5.6+: disable optimistic writes during large appends (default `true`) |

Set `enable_optimistic_write` through the constructor configuration array or
`SET enable_optimistic_write = false`; no new PHP method is needed. If you use
a distribution-provided library, check the linked engine version because older
engines may reject the setting. See the
[upstream change](https://github.com/duckdb/duckdb/pull/26102).

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

The C API's *option enumeration* functions (`duckdb_config_count()`,
`duckdb_get_config_flag()`) let C programs discover options at runtime. They
have no PHP wrapper; consult the upstream documentation or query DuckDB through
SQL for the live list:

```php
$conn->query('SELECT name, value, description FROM duckdb_settings()')->fetchAll();
```
