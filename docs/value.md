# Values

PHP counterpart of the C API's **value interface** (`duckdb_value`,
`duckdb_create_varchar()`, `duckdb_create_struct_value()`,
`duckdb_create_list_value()`, `duckdb_destroy_value()`, …).

## Short version: there is no `Value` object

The C value interface exists so C programs can build DuckDB-typed values
outside of SQL text. PHP already has rich native types, so this driver maps
PHP values directly instead of wrapping `duckdb_value` handles:

| C value constructor | PHP equivalent |
|---|---|
| `duckdb_create_varchar()` | a PHP `string` |
| `duckdb_create_blob()` | `Statement::bindBlob($param, $string)` |
| `duckdb_create_int64()` / `duckdb_create_double()` / … | PHP `int` / `float` / `bool` |
| `duckdb_create_date()` / `duckdb_create_timestamp()` | `DateTimeImmutable` (or any `DateTimeInterface`) |
| `duckdb_create_time_tz()` | not bindable; produce TIME values in SQL |
| `duckdb_create_interval()` | `new DuckDB\Interval($months, $days, $micros)` |
| `duckdb_create_uuid()` | canonical UUID `string` |
| `duckdb_create_decimal()` | exact decimal `string` (e.g. `'123.45'`) with a DECIMAL target |
| `duckdb_create_bignum()` | arbitrary-precision decimal `string` with a BIGNUM/DECIMAL target |
| `duckdb_create_bit()` | binary `string` via `bindBlob()` with a BIT target |
| `duckdb_create_enum_value()` | the member label `string` |
| `duckdb_create_list_value()` | PHP `list` (binds as LIST) |
| `duckdb_create_array_value()` | PHP `list` with an ARRAY target in SQL |
| `duckdb_create_struct_value()` | PHP assoc array (binds as STRUCT) |
| `duckdb_create_map_value()` | build in SQL (`map {...}`), or bind a STRUCT/list representation and cast |
| `duckdb_create_union_value()` | bind the member value; cast to the UNION type in SQL |
| `duckdb_create_null_value()` | `null` |

Where a bind goes in (prepared statements, `Connection::execute()`, the
Appender) the conversion is automatic — see the
[type mapping tables](types.md).

## Reading values back

Results are likewise delivered as native PHP values directly; there is no
`duckdb_value_*` getter layer. See [types.md](types.md#duckdb--php-query-results)
for the full DuckDB→PHP table.

Two deliberate simplifications relative to the C interface:

- **DECIMAL and overflowing HUGEINT/UHUGEINT come back as exact decimal
  strings**, so no precision is silently lost to a PHP float/int. Cast
  explicitly when you want a number.
- **VARIANT values materialize as their JSON string rendering** in buffered
  results.

## When you actually need SQL-side construction

Composite values that have no direct PHP literal (MAP, UNION, ARRAY, ENUM
creation) are most idiomatically built in SQL and combined with bound
scalars:

```php
$conn->execute(
    'INSERT INTO t SELECT ?, map {? : ?}',
    [$id, $key, $value],
);
```
