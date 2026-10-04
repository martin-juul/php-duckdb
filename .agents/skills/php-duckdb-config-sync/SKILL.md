---
name: php-duckdb-config-sync
description: Keep php-duckdb build, SDK, packaging, CI and IDE configurations aligned when native sources, required DuckDB APIs, build settings or supported environments change. Use also for explicit configuration audits.
---

# Configuration synchronization

Use this skill in the php-duckdb repository. Follow the changed dependency
through its consumers; update the affected configurations in the same task.
Do not upgrade unrelated dependencies or platforms during a focused change.

## Trace the configuration

Start with the diff and the relevant rows below. Search for consumers rather
than assuming this table is an exhaustive file inventory.

| Change | Configurations to reconcile |
| --- | --- |
| Native source or header | `CMakeLists.txt`, `config.m4`, `config.w32`; CMake IDE headers and compilation database |
| PHP requirement or build flags | `composer.json`, PHP discovery/version/ZTS checks, phpize and Windows builds, supported PHP CI matrix |
| Required DuckDB API | Compile/link capability checks, SDK source pin and patches, all platform SDK builders |
| SDK source, patch or flags | `packaging/duckdb/source.json`, SDK builders and provenance, `.github/scripts/sdk-cache-key.py`, cache action and tests |
| Package dependency or layout | Affected distro recipes, private engine name/search path, installed-module smoke and packaging workflow |
| Supported environment | `.github/workflows/packaging.yml`, native bootstrap tools, runtime slots, architecture, packaging and compatibility docs |
| Public PHP API | Stub and regenerated arginfo, API docs, examples and `examples/README.md` coverage index |

Run the deterministic native source check from the repository root:

```sh
python3 .agents/skills/php-duckdb-config-sync/scripts/check_sources.py
```

It compares `duckdb.cpp` and `src/**/*.cpp` with the three source manifests.
It cannot establish ABI compatibility, successful linking, complete header
indexing, or platform support.

## Preserve project invariants

- Packages must use the pinned, patched engine. A system library with the same
  version or exported symbols does not prove the patches are present. Keep
  source/patch hashes, cache identity and installed provenance consistent.
- SDK cache keys must describe the effective native flags. Budget compilation
  and LTO workers using `packaging/resources/jobs.py`; appending a bounded LTO
  flag need not override an earlier automatic setting.
- Private engine SONAME/dependency rewriting must survive native debug/strip
  processing. Validate the final installed ELF and loader behavior; intermediate
  build artifacts are insufficient. Keep normal debug processing enabled.
- CMake must work as an IDE entry point as well as an auxiliary build. Check a
  bare configure in a fresh directory, explicit SDK selection, and reconfigure
  after switching SDKs. Discovery must respect explicit choices and avoid
  silently substituting an unpatched system engine. Document any bootstrap
  download/build and use the existing SDK builder instead of duplicating it.
- Keep CMake commands compatible with `cmake_minimum_required`. Derive PHP
  include paths and ZTS from the selected PHP development installation, and
  fail clearly on discovery errors. Exercise missing SDK capabilities at both
compile and link time when capability checks change.

When validating an explicit extension path on a machine with DuckDB already
installed, isolate its autoload ini entry from the test process. Preserve the
other PHP extensions and diagnostics. Loading the installed and newly built
modules together can cause duplicate-module warnings and subprocess crashes.

## Validate the affected paths

Use `php tests/harness.php` for extension builds and behavior checks, following
`AGENTS.md` for full-suite requirements. For CMake changes, also configure and
build the auxiliary module, then run harness checks against that exact module;
consult `--help` for current switches. Check the declared minimum CMake version
when introducing commands or options. Test fresh IDE configuration without
relying on a previous developer's cache.

For SDK/cache changes, run the relevant tests under `packaging/duckdb/tests`
and `.github/tests`. For packaging changes, build/install in the affected
environment and run `packaging/smoke.php` through its normal PHP configuration.
Check private library resolution and patch provenance. A mock or YAML syntax
check does not replace a native package build.

Update affected setup and compatibility documentation with actual validation
results. Use the repository Markdown lint skill and `git diff --check`.
Report untested architectures, runtime branches and platform link paths
explicitly; a configuration entry alone is not validated support.
