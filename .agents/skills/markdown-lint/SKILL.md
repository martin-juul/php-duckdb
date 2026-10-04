---
name: markdown-lint
description: Check and fix Markdown formatting in this repository using its pinned linter and configuration. Use for documentation edits or Markdown lint failures.
---

# Markdown lint

Run from the repository root:

```sh
python3 .agents/skills/markdown-lint/scripts/lint.py
```

The script checks tracked Markdown using `markdownlint-cli2@0.23.3` and the
root `.markdownlint.json`. It requires Python 3, Git, Node.js and npm; npm may
fetch the pinned tool on its first run. It does not install project packages.
Generated or downloaded files outside Git's index are excluded.

Pass explicit paths to check selected or newly created files before staging:

```sh
python3 .agents/skills/markdown-lint/scripts/lint.py README.md docs/types.md
```

Fix reported formatting, then rerun the same command and `git diff --check`.
Preserve code examples, commands, API names, links and technical claims.
Review any autofix diff; do not disable rules to conceal an error. Repeated
subheadings under different parent sections are allowed by the configuration.
Formatting checks do not verify examples or technical accuracy; run the
relevant harness checks when their behavior changes.
