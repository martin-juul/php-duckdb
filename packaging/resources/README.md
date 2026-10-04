# Resource-aware worker counts

The build scripts and test harness choose parallel workers from the CPU and
memory available to their process at the start of each stage. Explicit settings take
precedence; the calculation does not change CPU affinity, container limits or
system settings.

```sh
python3 packaging/resources/jobs.py --profile sdk
python3 packaging/resources/jobs.py --profile valgrind --json
```

The calculation uses 75% of available CPUs and 75% of available memory, taking
the smaller worker count. It always returns at least one worker. Memory budgets
per worker are planning estimates:

| Profile | Memory per worker |
| --- | --- |
| `sdk` | 3 GiB |
| `extension` | 512 MiB |
| `test` | 512 MiB |
| `valgrind` | 2 GiB |

Linux detection accounts for CPU affinity and cgroup v1/v2 limits, including
ancestor quotas and remaining memory. Other platforms use their system CPU and
memory interfaces. Unknown memory availability selects one worker. The JSON
output shows the detected limits and chosen count.

Use `--jobs=N` or `DUCKDB_JOBS` for the test harness; `--jobs` wins when both
are set. SDK builders accept `DUCKDB_BUILD_JOBS` and their existing `--jobs`
or `-Jobs` option. Docker builds accept the `DUCKDB_BUILD_JOBS` build argument
for engine and extension compilation. macOS and Solaris extension builds use
`DUCKDB_JOBS`; their engine builds use `DUCKDB_BUILD_JOBS`.

Python 3 is required by the SDK builders. The PHP test harness falls back to
one worker, with a diagnostic, if Python or the helper is unavailable.
Distribution package managers retain their own extension build controls,
such as Portage's `MAKEOPTS`; their SDK builds use this calculation.

For CI failure reproduction, retain the original quota and an explicit worker
count. More parallel test processes can shorten a suite, but an individual
Memcheck process generally executes on one CPU.
