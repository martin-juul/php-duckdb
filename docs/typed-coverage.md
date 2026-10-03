# Typed input coverage

The coverage target is every user-facing type family in DuckDB 1.5.6. The
[matrix test](../tests/070_typed_value_matrix.phpt) verifies native SQL `typeof`
and equality through `bindValue()`, execution parameter arrays, `appendRow()`
and `append()`, plus typed NULL through all four paths.

| Family | Matrix cases | Additional checks |
|---|---|---|
| Boolean and integers | BOOLEAN, signed and unsigned 8/16/32/64/128-bit types | Signed minima, unsigned maxima, HUGEINT/UHUGEINT exact string boundaries, overflow rejection. |
| Arbitrary/exact numeric | BIGNUM, DECIMAL | Long exact integers, positive/negative rounding. |
| Floating point | FLOAT, DOUBLE | NaN and positive/negative infinity. |
| Temporal | DATE, TIME, TIME_NS, TIMETZ, TIMESTAMP_S/MS/NS, TIMESTAMP, TIMESTAMPTZ, INTERVAL | Nanoseconds, DateTime snapshots, infinities, deferred connection timezone settings. |
| Text and binary | VARCHAR, BLOB, BIT, UUID, JSON | Embedded binary NUL, textual bits, malformed JSON/UUID/BIT rejection. |
| ENUM | Inline ENUM | Transaction-local catalog ENUM and rollback. |
| Collections | LIST and fixed ARRAY | Empty, all-null and nested lists, invalid lengths and non-sequential inputs. |
| STRUCT | Declared fields | Declaration order, missing/unknown fields, nested wrappers. |
| MAP | String and composite keys | Numeric-looking strings, composite and empty list keys, null/duplicate keys after coercion. |
| UNION | Tagged nullable member | Native selected tag inspected in SQL, malformed tags/shapes. |
| VARIANT | Explicit JSON source | Plain string retains VARCHAR; nested UTINYINT wrapper retains UINT8 source type. |
| GEOMETRY | WKT | CRS84 metadata and explicitly typed binary WKB source through all paths. |
| Catalog and aliases | Built-in SQL aliases | Qualified/quoted catalog type, per-connection alias resolution. |

[Snapshot and declaration tests](../tests/071_typed_value_snapshot.phpt) cover
reference detachment, unsupported PHP objects, cycles, depth limits, injection
attempts and denied serialization.
[Composite tests](../tests/072_typed_value_composites.phpt) inspect native types
and tags without relying on lossy result decoding.
[Edge and stream tests](../tests/073_typed_value_edges.phpt) check geometry CRS,
connection settings and stream invalidation.
[Integration tests](../tests/074_typed_integration.phpt) exercise binding
replacement, clearing, async conversion, failure atomicity and Appender recovery.
[Native class tests](../tests/075_type_classes.phpt) exercise all 39 dedicated
classes through every ingestion path, nested schema arguments and custom
subclasses. [Registry tests](../tests/076_typed_registry.phpt) verify bounded
helper lifetime, shared file instances, transaction visibility and concurrent
connections.
[Destructor tests](../tests/077_typed_destructor.phpt) verify that replacing or
clearing custom typed values releases PHP objects outside the connection lock.

The [adapter fixture](../examples/typed_adapter.php) verifies connection-free
custom type conversion and the one-placeholder DuckDB LIST contract. The
[benchmark](../benchmarks/typed_bindings.php) compares ordinary inputs with typed
scalar and composite batches. Run it with the built extension loaded; conversion
cost depends on DuckDB settings, platform and workload.
See [measured conversion overhead](../benchmarks/README.md).

Validation on PHP 8.5.11 NTS with DuckDB 1.5.6: phpize/configure/make and CMake
builds pass; 74 PHPTs pass, with one skipped because the optional true-async
runtime is unavailable. Swoole, AMPHP and ReactPHP tests pass. All 14 examples
and both stress scripts pass. Focused Valgrind checks cover snapshots, native
classes, conversion errors, Appender, async use and registry cleanup.
