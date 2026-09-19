# Types

PHP counterpart of the C API's type system (`duckdb_logical_type`,
`duckdb_decimal`, `duckdb_interval`, …). In PHP you never construct logical
types by hand — values decode to native PHP types on the way out and encode
from native PHP types on the way in.

## DuckDB → PHP (query results)

| DuckDB type | PHP value |
|---|---|
| BOOLEAN | `bool` |
| TINYINT / SMALLINT / INTEGER / UTINYINT / USMALLINT / UINTEGER | `int` |
| BIGINT / UBIGINT | `int` |
| HUGEINT / UHUGEINT | `int` when it fits, otherwise `string` (exact decimal) |
| FLOAT / DOUBLE | `float` |
| DECIMAL | `string` — exact decimal, **no precision loss** |
| VARCHAR | `string` |
| ENUM | `string` (the member label) |
| BLOB | `string` (binary) |
| BIT | `string` (binary bitstring layout) |
| GEOMETRY | `string` (binary) |
| UUID | `string` (canonical `xxxxxxxx-xxxx-…` form) |
| DATE | `DateTimeImmutable` (midnight, UTC) |
| TIMESTAMP / TIMESTAMP_S / _MS / _NS / TIMESTAMPTZ | `DateTimeImmutable` (UTC) |
| TIME / TIMETZ / TIME_NS | `string` |
| INTERVAL | `DuckDB\Interval` |
| LIST / ARRAY | `list<mixed>` (recursively decoded) |
| STRUCT | `array<string, mixed>` (recursively decoded) |
| MAP | assoc `array` for scalar keys; a list of `['key' => k, 'value' => v]` pairs for non-scalar keys; a NULL key maps to `''` |
| UNION | the member value, unwrapped |
| VARIANT | `string` (JSON rendering) |
| NULL | `null` |

Precision and edge notes:

- **DECIMAL is always a string.** Cast with `(float)` / `bcmul()` / etc. only
  when you consciously accept the conversion.
- **Non-finite temporal values** (`'infinity'::TIMESTAMP`, `-infinity`) are
  returned as strings, since `DateTimeImmutable` cannot represent them.
- **Duplicate MAP keys** overwrite earlier ones in the assoc-array rendering
  (standard PHP array semantics).
- Nested decoding is depth-capped at 512 levels; deeper structures throw
  instead of overflowing the stack.

## PHP → DuckDB (binding / appending)

| PHP value | DuckDB type |
|---|---|
| `null` | NULL |
| `bool` | BOOLEAN |
| `int` | BIGINT |
| `float` | DOUBLE |
| `string` | VARCHAR (use `Statement::bindBlob()` for BLOB) |
| `DuckDB\Interval` | INTERVAL |
| `DateTimeInterface` | TIMESTAMP (microsecond precision) |
| `list<mixed>` | LIST |
| `array<string, mixed>` | STRUCT |

## `DuckDB\Interval`

`INTERVAL` has no sane native PHP equivalent (`DateInterval` cannot represent
months+days+microseconds faithfully), so the driver provides a small value
object:

```php
use DuckDB\Interval;

$i = new Interval(months: 4, days: 5, micros: 60_000_000);
echo $i;                     // "4 months 5 days 00:01:00"
$i->getMonths();             // 4
Interval::fromSeconds(90.5); // 1 minute 30.5 seconds
json_encode($i);             // {"months":4,"days":5,"micros":60000000}
```

## Checking types without fetching

```php
$result->columnType(0);          // 'DECIMAL(18,2)'
$stmt->columnType(0);            // works on prepared statements too
$stmt->parameterType('name');    // 'VARCHAR'
```

## Not exposed

The C API's *logical type construction* functions
(`duckdb_create_logical_type()`, `duckdb_create_list_type()`,
`duckdb_register_logical_type()`, …) exist for C extensions and UDF
registration. Since PHP user-defined functions are not part of this driver
(see [table_functions.md](table_functions.md)), logical type handles are not
exposed either. Struct/list/union values are *described* by the SQL type
system and *consumed* as native PHP arrays.
