# Repository instructions

- Use `php tests/harness.php` for builds and validation.
- Allocate workers dynamically from available CPU and memory using
  `packaging/resources/jobs.py`; honor explicit overrides and CI reproduction
  quotas. Parallelize independent work within that budget.
- Before pushing native-code or PHPT changes, run `php tests/harness.php --full`
  (build, unit, examples, full Valgrind suite, stress). Focused checks do not
  replace the full suite.
- Reproduce CI failures with the failing PHP version, runtime extensions and
  DuckDB SDK and CPU quota. Use containers when local versions differ.
- Fix leaks, crashes and test failures before claiming success. Do not hide them
  with skips, suppressions or disabled checks.
- Make subprocess timeouts account for Valgrind overhead without removing
  deadlock detection.
- Validate packaging/workflow changes in the affected environment. Retry only
  recoverable operations, with bounded attempts and timeouts.
- After pushing, check CI and address failures. Report exact checks, skips and
  unresolved failures; never call a partial pass complete.
- After each push, cancel queued and running CI workflows for older commits
  on the same branch. Keep only the latest commit's runs; do not retain stale
  builds just to populate caches. Confirm cancellation has completed.
- Edit `duckdb.stub.php` and regenerate arginfo; do not hand-edit generated
  signatures.
- Update affected docs/examples; use the
  [Markdown lint skill](.agents/skills/markdown-lint/SKILL.md) and
  `git diff --check`.
- Keep one statement per line, expand control-flow blocks, and separate logical
  steps with blank lines. Match the surrounding code style.
- Keep generated build files, local IDE settings and unrelated changes out of
  commits.
