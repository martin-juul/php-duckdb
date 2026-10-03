# Repository instructions

- Use `php tests/harness.php` for builds and validation.
- Before pushing native-code or PHPT changes, run `php tests/harness.php --full`
  (build, unit, examples, full Valgrind suite, stress). Focused checks do not
  replace the full suite.
- Reproduce CI failures with the failing PHP version, runtime extensions and
  DuckDB SDK. Use containers when local versions differ.
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
- Update affected docs/examples, check Markdown formatting, and run
  `git diff --check`.
- Keep generated build files, local IDE settings and unrelated changes out of
  commits.
