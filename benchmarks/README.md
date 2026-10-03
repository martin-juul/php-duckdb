# Typed binding benchmark

Run against the built extension:

```sh
php -n -d extension=modules/duckdb.so benchmarks/typed_bindings.php 1000
```

The script prepares `SELECT` statements, warms each case for 20 executions, then
measures execution with parameter arrays. Wrappers and statements are created
before timing. DuckDB uses one query thread. Results include ordinary query
execution as well as binding and conversion; they are not isolated cast costs or
a throughput prediction for larger queries.

Sample on Linux x86-64, PHP 8.5.11 NTS, DuckDB 1.5.6, 1,000 executions per case
(2026-10-03):

| Parameters per execution                              | Before this change | Current ordinary inputs | Current typed inputs |
| ----------------------------------------------------- | -----------------: | ----------------------: | -------------------: |
| One scalar                                            |           121.1 µs |                124.1 µs |             480.0 µs |
| Eight scalars                                         |           158.3 µs |                163.2 µs |           1,629.4 µs |
| Eight structs containing an integer and a string list |           255.7 µs |                257.0 µs |           2,768.4 µs |

Ordinary binding timings stayed within 4% of the baseline in this run. Typed
conversion added approximately 0.36 ms for one scalar, 1.47 ms for eight
scalars, and 2.51 ms for eight structs. Scalar typed cases exercise INTEGER and
DECIMAL casts from strings; ordinary scalar cases use PHP integers.

Typed inputs resolve metadata and perform DuckDB casts on each execution.
Conversion is batched into one helper execution, and native converted values are
never cached across executions. This preserves current catalog definitions and
connection settings, at a measurable cost for small queries. Machine load,
DuckDB configuration and input complexity affect these timings.

## Parsing and batch metadata optimization

Declaration parsing happens when a wrapper is constructed. It is separate from
DuckDB's SQL preparation during execution. Run the connection-free construction
benchmark to measure parsing, snapshotting, object allocation and destruction
(including PHP closure overhead):

```sh
php -n -d extension=modules/duckdb.so benchmarks/typed_construction.php 100000
```

The binding benchmark also includes mixed typed/ordinary inputs and eight
individually distinct types. Its wrappers are constructed before timing.
Repeated declarations now share resolved metadata within a single conversion.
The cache holds at most 64 declarations, then falls back to uncached resolution;
it is destroyed after each statement execution or Appender row. It never caches
converted values or persists across executions or connections.

Comparison against commit `581bfb6`, on the same Linux/PHP/DuckDB configuration
as above. Numbers are medians of three runs (1,000 executions or 100,000
constructions per run), with no concurrent test workloads:

| Execution case                         |     Before |  Optimized | Reduction |
| -------------------------------------- | ---------: | ---------: | --------: |
| One typed scalar                       |   477.3 µs |   475.5 µs |      0.4% |
| Eight typed decimals, same declaration | 1,580.4 µs |   756.0 µs |     52.2% |
| Eight mixed typed/ordinary values      | 1,154.8 µs |   912.1 µs |     21.0% |
| Eight different typed declarations     | 1,362.4 µs | 1,405.7 µs |     -3.2% |
| Eight typed structs, same declaration  | 2,732.1 µs | 1,830.5 µs |     33.0% |

Ordinary binding varied between -2.4% and +2.3% in these runs. Distinct-type
batches receive no reuse benefit and pay a small cache lookup/insertion cost;
the measured change also includes run-to-run noise.

| Construction case                             |   Before | Optimized |
| --------------------------------------------- | -------: | --------: |
| Generic INTEGER                               | 0.246 µs |  0.220 µs |
| Native Integer                                | 0.227 µs |  0.245 µs |
| Native Decimal                                | 0.538 µs |  0.512 µs |
| INTERVAL declaration                          | 0.870 µs |  0.566 µs |
| STRUCT declaration with 16 fields, null input | 3.886 µs |  3.547 µs |
| Native Struct with 16 fields and values       | 6.522 µs |  6.212 µs |

Parser changes avoid temporary uppercase strings and token copies, reuse the
INTERVAL keyword set, and avoid recomputing the type name for every field.
Scalar construction remains sub-microsecond; its small timing changes should not
be treated as a guaranteed speedup. The larger execution gain comes from
avoiding repeated DuckDB metadata preparation for the same declaration.
