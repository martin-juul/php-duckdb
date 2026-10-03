# Repository instructions

- Use `php tests/harness.php` for builds and validation.
- Use available local resources for independent work, up to 12 workers on this
  machine. Preserve the original CPU quota when reproducing CI failures.
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
- Edit `duckdb.stub.php` and regenerate arginfo; do not hand-edit generated
  signatures.
- Update affected docs/examples; use the
  [Markdown lint skill](.agents/skills/markdown-lint/SKILL.md) and
  `git diff --check`.
- Keep one statement per line, expand control-flow blocks, and separate logical
  steps with blank lines. Match the surrounding code style.
- Keep generated build files, local IDE settings and unrelated changes out of
  commits.
