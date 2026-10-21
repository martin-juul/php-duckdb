# Valgrind fiber stack unwinding

The local Linux x86_64 combination of PHP 8.5.11 and Valgrind 3.27.1 can
crash inside Valgrind while running `tests/015_fiber.phpt`. The test runner
reports a leaked test, but the `.mem` file contains `VALGRIND INTERNAL ERROR`
and a SIGSEGV in `coregrind/m_stacktrace.c:749`.

GDB confirmed that the fallback stack walk reads beyond the PHP fiber's
mapping while its estimated upper stack bound extends into an adjacent
region. Explicit fiber stack registration did not prevent the failure.
The patch checks that the next word belongs to readable client memory
before dereferencing it. It preserves Memcheck checks, leak reporting,
and the configured stack depth.

## Build and use

The builder pins the official Valgrind 3.27.1 archive and verifies its SHA256.
It requires Bash, Python 3, curl, tar with bzip2 support, Git, GCC, and make.
It selects workers with `packaging/resources/jobs.py`; `DUCKDB_JOBS` overrides
the worker count when sharing resources with another build.

```sh
packaging/valgrind/build.sh
```

The default prefix is
`${XDG_CACHE_HOME:-$HOME/.cache}/php-duckdb/valgrind-3.27.1-stack-unwind`.
Pass an installation prefix as the first argument to choose another path.
Build failures retain their logs and source directory in the cache.

Select the patched tool explicitly when running validation:

```sh
valgrind_prefix="${XDG_CACHE_HOME:-$HOME/.cache}/php-duckdb/valgrind-3.27.1-stack-unwind"
PATH="$valgrind_prefix/bin:$PATH" php tests/harness.php --full
```

The harness continues to use whichever `valgrind` is on `PATH`. The builder
does not change system tools, add suppressions, or disable checks.

## Validation

On Linux x86_64, the original tool reproducibly crashed in the focused
fiber test. The patched tool passed the same harness test with the usual
Memcheck options: one test passed, no failures or skips. The extension was
PHP 8.5.11 NTS with the project's patched DuckDB SDK.

```sh
PATH="$valgrind_prefix/bin:$PATH" php tests/harness.php valgrind \
    --filter='015_fiber*' --jobs=1
```

This patch has not been validated on other operating systems or architectures;
the builder accepts Linux x86_64 only.
