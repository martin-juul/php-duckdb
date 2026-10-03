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
