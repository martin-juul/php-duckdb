# Typed values

The extension provides native PHP classes directly under `DuckDB`. They need
no Composer package or autoloader. Each value snapshots its input and carries
its declared DuckDB type:

```php
use DuckDB\{Decimal, Integer, ListValue, Struct, Varchar};

$amount = new Decimal('12.345', precision: 18, scale: 2);
echo $amount->getType(); // DECIMAL(18, 2)
$stmt = $conn->prepare('SELECT $amount');
$stmt->bindValue('amount', $amount);
$result = $stmt->execute(); // DuckDB rounds to 12.35 here
$conn->execute('SELECT ?', [new ListValue([], Integer::class)]);
$appender->appendRow([
    $amount,
    new Struct(['label' => 'sample'], fields: ['label' => Varchar::class]),
]);
```

## Scalar classes

Every scalar constructor takes one argument, `mixed $value`. Every class
accepts `null` and retains its declared SQL type. These classes are final and
extend `DuckDB\Value`; `getType()` is final and inherited.

| Classes | SQL targets |
|---|---|
| `Boolean` | BOOLEAN |
| `TinyInt`, `SmallInt`, `Integer`, `BigInt` | TINYINT, SMALLINT, INTEGER, BIGINT |
| `UTinyInt`, `USmallInt`, `UInteger`, `UBigInt` | Unsigned equivalents |
| `HugeInt`, `UHugeInt`, `BigNum` | HUGEINT, UHUGEINT, BIGNUM |
| `Float32`, `Double` | FLOAT, DOUBLE |
| `Varchar`, `Blob`, `Bit`, `Uuid`, `Json` | VARCHAR, BLOB, BIT, UUID, JSON |
| `Date`, `Time`, `TimeNs`, `TimeTz` | DATE, TIME, TIME_NS, TIMETZ |
| `TimestampS`, `TimestampMs`, `Timestamp`, `TimestampNs`, `TimestampTz` | TIMESTAMP_S, TIMESTAMP_MS, TIMESTAMP, TIMESTAMP_NS, TIMESTAMPTZ |
| `IntervalValue` | INTERVAL; accepts temporal strings or `DuckDB\Interval` |
| `Variant` | VARIANT, preserving source types recursively |

`DuckDB\Interval` remains the existing months/days/microseconds component and
result object. `IntervalValue` supplies explicit typed input. Names such as
`Float32`, `Varchar`, `ListValue` and `ArrayValue` avoid PHP reserved class names.

## Parameterized classes and composites

Type specifications accept a SQL type string, a scalar class name such as
`Integer::class`, or an existing `Value` whose declared type supplies the schema.
A typed NULL is a convenient schema for parameterized or nested types:

```php
use DuckDB\{ArrayValue, Blob, CatalogValue, Decimal, Enum, Geometry,
    Integer, ListValue, Map, Struct, Union, Varchar};

$priceType = new Decimal(null, precision: 18, scale: 2);
$prices = new ListValue(['1.25', '2.50'], elementType: $priceType);
$empty = new ListValue([], elementType: Integer::class);
$fixed = new ArrayValue([1, 2], elementType: Integer::class, length: 2);
$nested = new ListValue([[], [1]], elementType: new ListValue(null, Integer::class));
$record = new Struct(['price' => '1.25', 'id' => 7], fields: [
    'id' => Integer::class,
    'price' => $priceType,
]);
$map = new Map(
    [['key' => '01', 'value' => 7]],
    keyType: Varchar::class,
    valueType: Integer::class,
);
$selectedNull = new Union(null, tag: 'name', members: [
    'id' => Integer::class,
    'name' => Varchar::class,
]);
$wholeUnionNull = new Union(null, tag: null, members: ['id' => Integer::class]);
$enum = new Enum('blue', labels: ['red', 'blue']);
$geometry = new Geometry('POINT (1 2)', crs: 'OGC:CRS84');
$binaryGeometry = new Geometry(new Blob($wkb), crs: 'OGC:CRS84');
$catalogType = new CatalogValue('happy', name: 'mood', schema: 'custom');
```

| Constructor | Parameters after `mixed $value` |
|---|---|
| `Decimal` | `int $precision = 18, int $scale = 3` |
| `Enum` | `array $labels` |
| `ListValue` | `string\|Value $elementType` |
| `ArrayValue` | `string\|Value $elementType, int $length` |
| `Struct` | `array $fields` (field name → type specification) |
| `Map` | `string\|Value $keyType, string\|Value $valueType` |
| `Union` | `?string $tag, array $members` (member name → type specification) |
| `Geometry` | `?string $crs = null` |
| `CatalogValue` | `string $name, ?string $schema = null, ?string $catalog = null` |

`CatalogValue` quotes each identifier component independently. It resolves the
named type on the consuming connection, including transaction-local catalog
types. `Geometry` quotes the CRS declaration and preserves native CRS metadata.
Neither class installs extensions.

## Advanced declarations

The extensible base class remains an escape hatch for SQL aliases and arbitrary
extension-provided declarations:

```php
$value = new DuckDB\Value('DECIMAL(18,2)', '12.345');
$alias = new DuckDB\Value('INT4', 7);
```

All native typed classes use the same snapshot and conversion machinery as the
base wrapper. No separate userland conversion library is required.

Construction validates a type declaration and snapshots the PHP input without
connecting. References inside arrays are detached, and mutable DateTime inputs
are snapshotted. Wrappers can nest inside collections and be reused across
statements and connections. `getType()` returns the canonical declared type,
not a connection's catalog expansion. Serialization is denied. There is no
general value string renderer in this release.

## Conversion timing

`bindValue()` checks the parameter name or position immediately and records the
wrapper. On each execution, the original connection resolves catalog types and
performs DuckDB casts. Changes to catalog definitions, timezone settings and
transaction-local types are visible. No converted native value is cached.
Typed parameters are converted together before user-query execution, including
streaming and async execution. PHP inputs never reach query worker threads.

`append()` converts before submitting the affected value. `appendRow()` converts
all values together before beginning the row. Conversion failure leaves the
row untouched. Conversion executes SQL on the same connection and can invalidate
an existing stream; binding a statement alone does not invalidate it.

Values are bound as parameters, never interpolated into conversion SQL. Type
declarations accept quoted/qualified names, SQL aliases, nested types, ENUM
labels, ARRAY suffixes and geometry CRS parameters. Expressions, comments,
statement separators, NUL bytes and trailing tokens are rejected. Internal
markers such as ANY and INVALID are not SQL value types. Extension-provided
types require their extension already loaded; conversion never installs one.

Malformed declarations and collection shapes throw `ValueError`. Unsupported
PHP inputs throw `TypeError`. DuckDB resolution and cast errors use the existing
extension exception hierarchy.

## Input contract

| Target | Input |
|---|---|
| BOOLEAN; all signed/unsigned integers; HUGEINT/UHUGEINT; BIGNUM; FLOAT/DOUBLE; DECIMAL | PHP numbers or strings cast by DuckDB. Use strings for exact values beyond PHP precision. |
| DATE, TIME, TIME_NS, TIMETZ, TIMESTAMP_S/MS/NS, TIMESTAMP, TIMESTAMPTZ | Temporal strings or existing DateTime inputs. Strings preserve nanoseconds and infinities. |
| INTERVAL | Temporal string or `DuckDB\Interval`. |
| VARCHAR | PHP string. |
| BLOB | Binary-safe PHP string, including NUL bytes. |
| BIT | Textual bit sequence. |
| UUID, JSON | String validated by DuckDB. |
| Inline or catalog ENUM | Label string. |
| LIST (`T[]`) | Sequential array; empty and all-null inputs retain the declared child type. |
| Fixed ARRAY (`T[N]`) | Sequential array of exactly N elements. |
| STRUCT | Associative array with exactly the declared fields; declaration order is preserved. |
| MAP | List of `['key' => key, 'value' => value]` pairs. Composite keys and numeric-looking strings survive; DuckDB checks null and duplicate keys after coercion. |
| UNION | `['tag' => memberName, 'value' => payload]`; a null payload retains the selected member. |
| VARIANT | Native PHP source types recursively, including nested wrappers. A string stays a string; use a JSON wrapper to interpret JSON. |
| GEOMETRY, including CRS-qualified declarations | WKT string or explicitly typed BLOB wrapper containing WKB. CRS metadata is preserved. |
| Any valid target | `null`, retaining the declared type. |

Every nested child can itself be a wrapper. Plain PHP bindings keep their
existing inferred mapping; wrapping is optional. Result decoding also keeps
its existing mapping: UNION results are unwrapped, VARIANT results render as
JSON, and some MAP keys lose information in PHP associative arrays. Verify
exact native types, UNION tags and composite MAP keys in SQL when needed.

See [typed examples](../examples/typed_values.php), [type mappings](types.md),
and the [roadmap](roadmap.md).
