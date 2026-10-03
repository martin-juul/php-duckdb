# Types

The driver converts between DuckDB values and native PHP types when reading
results and binding inputs. It handles the C API's type system
(`duckdb_logical_type`, `duckdb_decimal`, `duckdb_interval`, …) internally;
you never construct logical types by hand in PHP.

## DuckDB → PHP (query results)

| DuckDB type | PHP value |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| BOOLEAN | `bool` |
| TINYINT / SMALLINT / INTEGER / UTINYINT / USMALLINT / UINTEGER | `int` |
| BIGINT | `int` |
| UBIGINT | `int` when it fits, otherwise `string` (exact decimal) |
| HUGEINT / UHUGEINT | `int` when it fits, otherwise `string` (exact decimal) |
| FLOAT / DOUBLE | `float` |
| DECIMAL | `string` — exact decimal, **no precision loss** |
| BIGNUM | `string` (exact decimal) |
| VARCHAR | `string` |
| ENUM | `string` (the member label) |
| BLOB | `string` (binary) |
| BIT | `string` (textual `0`/`1` bit sequence) |
| GEOMETRY | `string` (binary) |
| UUID | `string` (canonical `xxxxxxxx-xxxx-…` form) |
| DATE | `DateTimeImmutable` (midnight, UTC) |
| `TIMESTAMP` / `TIMESTAMP_S` / `TIMESTAMP_MS` / `TIMESTAMP_NS` / `TIMESTAMPTZ` | `DateTimeImmutable` (UTC) |
| TIME / TIMETZ / TIME_NS | `string` |
| INTERVAL | `DuckDB\Interval` |
| LIST / ARRAY | `list<mixed>` (recursively decoded) |
| STRUCT | `array<string, mixed>` (recursively decoded) |
| MAP | assoc `array` for scalar keys; a list of `['key' => k, 'value' => v]` pairs for non-scalar keys; a NULL key maps to `''` |
| UNION | the member value, unwrapped |
| VARIANT | `string` (DuckDB JSON rendering) |
| NULL | `null` |

Precision and edge notes:

- **DECIMAL is always a string.** Cast with `(float)` / `bcmul()` / etc. only
  when you consciously accept the conversion.
- **Non-finite temporal values** (`'infinity'::TIMESTAMP`, `-infinity`) are
  returned as strings, since `DateTimeImmutable` cannot represent them.
- **Duplicate MAP keys** overwrite earlier ones in the assoc-array rendering
  (standard PHP array semantics).
- Nested decoding is depth-capped at 512 levels; deeper structures throw instead
  of overflowing the stack.
- BIGNUM and VARIANT decode from fetched chunks in buffered, streaming and
  nested results. Their internal storage layouts are verified for DuckDB
  1.5.5 and 1.5.6; other versions raise an explicit decoding error until audited.
- VARIANT rendering is JSON text, with a nesting limit of 64. It preserves
  numeric digits without passing through PHP floats, but JSON does not retain
  DuckDB's source type tags. SQL NULL remains PHP `null`.
- Nanosecond timestamps become microsecond `DateTimeImmutable` values using
  DuckDB's truncation toward zero. Use SQL string casts to retain nanoseconds.

## PHP → DuckDB (binding / appending)

| PHP value | DuckDB type |
| ---------------------- | ---------------------------------------------- |
| `null` | NULL |
| `bool` | BOOLEAN |
| `int` | BIGINT |
| `float` | DOUBLE |
| `string` | VARCHAR (use `Statement::bindBlob()` for BLOB) |
| `DuckDB\Interval` | INTERVAL |
| `DateTimeInterface` | TIMESTAMP (microsecond precision) |
| `list<mixed>` | LIST |
| `array<string, mixed>` | STRUCT |

For complete explicit input typing, including MAP, tagged UNION, VARIANT and
geometry, use the [native typed classes](value.md), such as `DuckDB\Decimal`,
`DuckDB\ListValue`, `DuckDB\Map` and `DuckDB\Geometry`. The generic
`DuckDB\Value` constructor remains available for advanced SQL declarations.
Plain inputs retain this mapping.

## `DuckDB\Interval`

`DateInterval` cannot faithfully represent the months+days+microseconds
components of `INTERVAL`. The driver provides a value object for that
representation:

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

The C API's _logical type construction_ functions
(`duckdb_create_logical_type()`, `duckdb_create_list_type()`,
`duckdb_register_logical_type()`, …) exist for C extensions and UDF
registration. The driver does not expose logical type handles because it does
not support PHP user-defined functions (see
[table_functions.md](table_functions.md)). SQL types _describe_ struct/list/union
values; PHP consumes them as native arrays.
