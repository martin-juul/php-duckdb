#!/usr/bin/env python3
"""Lint tracked Markdown, or explicit paths, with the repository configuration."""
import os
from pathlib import Path
import shutil
import subprocess
import sys


def main():
    root = Path(__file__).resolve().parents[4]
    npx = shutil.which('npx.cmd' if os.name == 'nt' else 'npx')
    if not npx or not shutil.which('git'):
        print('Markdown lint requires Git, Node.js and npm (npx).', file=sys.stderr)
        return 2
    if sys.argv[1:]:
        files = sys.argv[1:]
    else:
        result = subprocess.run(
            ['git', 'ls-files', '-z', '--', '*.md'], cwd=root,
            check=True, stdout=subprocess.PIPE,
        )
        files = [os.fsdecode(path) for path in result.stdout.split(b'\0') if path]
    if not files:
        print('No Markdown files to lint.')
        return 0
    return subprocess.run(
        [npx, '--yes', 'markdownlint-cli2@0.23.3',
         '--config', str(root / '.markdownlint.json'), *files], cwd=root,
    ).returncode


if __name__ == '__main__':
    sys.exit(main())
