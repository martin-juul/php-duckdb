# Typed input coverage

The coverage target is every user-facing type family in DuckDB 1.5.6. The
[matrix test](../tests/070_typed_value_matrix.phpt) checks native SQL `typeof`
and equality through `bindValue()`, execution parameter arrays, `appendRow()`
and `append()`. It also checks typed NULL through all four paths.

| Family                  | Matrix cases                                                                     | Additional checks                                                                             |
| ----------------------- | -------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Boolean and integers    | BOOLEAN, signed and unsigned 8/16/32/64/128-bit types                            | Signed minima, unsigned maxima, HUGEINT/UHUGEINT exact string boundaries, overflow rejection. |
| Arbitrary/exact numeric | BIGNUM, DECIMAL                                                                  | Long exact integers, positive/negative rounding.                                              |
| Floating point          | FLOAT, DOUBLE                                                                    | NaN and positive/negative infinity.                                                           |
| Temporal                | DATE, TIME, TIME_NS, TIMETZ, TIMESTAMP_S/MS/NS, TIMESTAMP, TIMESTAMPTZ, INTERVAL | Nanoseconds, DateTime snapshots, infinities, deferred connection timezone settings.           |
| Text and binary         | VARCHAR, BLOB, BIT, UUID, JSON                                                   | Embedded binary NUL, textual bits, malformed JSON/UUID/BIT rejection.                         |
| ENUM                    | Inline ENUM                                                                      | Transaction-local catalog ENUM and rollback.                                                  |
| Collections             | LIST and fixed ARRAY                                                             | Empty, all-null and nested lists, invalid lengths and non-sequential inputs.                  |
| STRUCT                  | Declared fields                                                                  | Declaration order, missing/unknown fields, nested wrappers.                                   |
| MAP                     | String and composite keys                                                        | Numeric-looking strings, composite and empty list keys, null/duplicate keys after coercion.   |
| UNION                   | Tagged nullable member                                                           | Native selected tag inspected in SQL, malformed tags/shapes.                                  |
| VARIANT                 | Explicit JSON source                                                             | Plain string retains VARCHAR; nested UTINYINT wrapper retains UINT8 source type.              |
| GEOMETRY                | WKT                                                                              | CRS84 metadata and explicitly typed binary WKB source through all paths.                      |
| Catalog and aliases     | Built-in SQL aliases                                                             | Qualified/quoted catalog type, per-connection alias resolution.                               |

- [Snapshot and declaration tests](../tests/071_typed_value_snapshot.phpt) cover
  reference detachment, unsupported PHP objects, cycles, depth limits, injection
  attempts and denied serialization.
- [Composite tests](../tests/072_typed_value_composites.phpt) inspect native
  types and tags without relying on lossy result decoding.
- [Edge and stream tests](../tests/073_typed_value_edges.phpt) check geometry
  CRS, connection settings and stream invalidation.
- [Integration tests](../tests/074_typed_integration.phpt) exercise binding
  replacement, clearing, async conversion, failure atomicity and Appender
  recovery.
- [Native class tests](../tests/075_type_classes.phpt) exercise all 39 dedicated
  classes through every ingestion path, nested schema arguments and custom
  subclasses.
- [Registry tests](../tests/076_typed_registry.phpt) verify bounded helper
  lifetime, shared file instances, transaction visibility and concurrent
  connections.
- [Destructor tests](../tests/077_typed_destructor.phpt) verify that replacing
  or clearing custom typed values releases PHP objects outside the connection
  lock.

[Batch metadata tests](../tests/078_typed_metadata_batch.phpt) check mixed
inputs, repeated declarations, catalog changes, connection isolation, and
uncached fallback beyond the 64-entry operation limit, including recursive
conversion and failure recovery.

[Result regression tests](../tests/079_result_type_limitations.phpt) check
exact UBIGINT overflow handling and BIGNUM/VARIANT decoding through buffered,
streaming and nested results. [Appender failure tests](../tests/080_appender_failure_contract.phpt)
distinguish helper conversion failures from native submission and flush errors.

The [scalar result matrix](../tests/081_result_decode_matrix.phpt) checks PHP
values across six execution paths and three fetch methods. Separate tests cover
[temporal boundaries](../tests/082_temporal_decode_edges.phpt),
[BIGNUM](../tests/083_bignum_decode.phpt),
[nested results](../tests/084_nested_result_matrix.phpt),
[Appender clear](../tests/085_appender_clear.phpt), and
[VARIANT](../tests/086_variant_result_matrix.phpt). These checks decode PHP
results rather than checking only SQL-side equality.

The [adapter fixture](../examples/typed_adapter.php) verifies connection-free
custom type conversion and the one-placeholder DuckDB LIST contract.

Run the [benchmark](../benchmarks/typed_bindings.php) with the built extension
loaded to compare ordinary inputs with typed scalar and composite batches.
Conversion cost depends on DuckDB settings, platform and workload; see
[measured conversion overhead](../benchmarks/README.md).

Validation with PHP 8.5.11 NTS and DuckDB 1.5.6 passes the phpize build and
81 PHPTs, with three optional-runtime tests skipped; AMPHP and ReactPHP tests
pass separately with their dependencies installed. An isolated CMake build
with PHP 8.5.11 and DuckDB 1.5.5 passes 80 PHPTs, with four optional-runtime
tests skipped. The CI TrueAsync image with PHP 8.6 ZTS and DuckDB 1.5.6 passes
81 PHPTs, with three optional-runtime tests skipped; a focused TrueAsync test
also passes without skipping. PHP 8.4 with DuckDB 1.5.6 passes the build and
81 PHPTs, with three optional-runtime tests skipped. Every environment passes
all 14 examples and both stress scripts.

The full PHP 8.4 harness with the patched SDK passes all 79 Memcheck tests,
with no warnings or errors and five existing exclusions. See the
[nullable integer persistence analysis](compatibility.md#memcheck-and-nullable-integer-persistence)
for the engine patch and standalone regression. Distribution-provided engines
need the corresponding backport.
