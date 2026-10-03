# Typed binding benchmark

Run against the built extension:

```sh
php -n -d extension=modules/duckdb.so benchmarks/typed_bindings.php 1000
```

The script creates wrappers and prepares `SELECT` statements before timing.
It warms each case for 20 executions, then measures execution with parameter
arrays using one DuckDB query thread. The measurements include query execution,
binding and conversion. They do not isolate cast costs or predict throughput
for larger queries.

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

Each execution resolves metadata and performs DuckDB casts for typed inputs.
One helper execution converts the batch; native converted values are never
cached across executions. Repeating the conversion preserves current catalog
definitions and connection settings, but adds measurable cost to small queries.
Machine load, DuckDB configuration and input complexity affect these timings.

## Parsing and batch metadata optimization

Constructing a wrapper parses its declaration; DuckDB prepares SQL later,
during execution. The connection-free construction benchmark measures parsing,
snapshotting, object allocation and destruction, including PHP closure
overhead:

```sh
php -n -d extension=modules/duckdb.so benchmarks/typed_construction.php 100000
```

The binding benchmark also covers mixed typed/ordinary inputs and eight
individually distinct types, with wrappers constructed before timing. During
a single conversion, repeated declarations share resolved metadata. The cache
holds at most 64 declarations; further declarations use uncached resolution.
The cache is destroyed after each statement execution or Appender row. It never
caches converted values or persists across executions or connections.

Comparison against commit `581bfb6`, on the same Linux/PHP/DuckDB configuration
as above. Numbers are medians of three runs (1,000 executions or 100,000
constructions per run), with no concurrent test workloads:

| Execution case                         |     Before |  Optimized | Change in execution time |
| -------------------------------------- | ---------: | ---------: | -----------------------: |
| One typed scalar                       |   477.3 µs |   475.5 µs |                0.4% less |
| Eight typed decimals, same declaration | 1,580.4 µs |   756.0 µs |               52.2% less |
| Eight mixed typed/ordinary values      | 1,154.8 µs |   912.1 µs |               21.0% less |
| Eight different typed declarations     | 1,362.4 µs | 1,405.7 µs |       3.2% more (slower) |
| Eight typed structs, same declaration  | 2,732.1 µs | 1,830.5 µs |               33.0% less |

Eight different declarations took 43.3 µs longer after the change in this
measurement; this case did not improve.

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

## Buffered result decoding

```sh
php -n -d extension=modules/duckdb.so benchmarks/result_decoding.php 5000
```

The row count defaults to 1,000 and is limited to 1–5,000. Each case uses one
DuckDB thread, one full warmup and the median of three runs. Statements are
prepared and each buffered result is executed before timing; the timed loop
fetches numeric rows, checks every value and counts the returned bytes. These
measurements include chunk fetching, decoding and PHP row handling. They do
not isolate decoder costs. SQL casts and query execution are excluded, and
streaming is not measured.

Sample on Linux x86-64, PHP 8.5.11 NTS and DuckDB 1.5.6 SDK/runtime, using
5,000 rows per run (2026-10-03):

| Result value          | Median time per row | Rows per second |
| --------------------- | ------------------: | --------------: |
| VARCHAR, 39 bytes     |            0.161 µs |       6,230,126 |
| BIGNUM, 39 digits     |            0.248 µs |       4,025,891 |
| BIGNUM, 1,000 digits  |           12.862 µs |          77,751 |
| VARIANT scalar string |            0.639 µs |       1,566,048 |
| VARIANT nested JSON   |            1.847 µs |         541,333 |

Each result repeats one non-null value. The VARIANT scalar returns seven JSON
bytes per row; the nested object returns 79. Payload size, nesting, machine load
and allocation behavior affect throughput. No before timings are reported:
the previous BIGNUM and VARIANT decoding paths did not return these values
correctly.
