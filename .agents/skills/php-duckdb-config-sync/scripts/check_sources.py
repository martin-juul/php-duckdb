#!/usr/bin/env python3
"""Check native source parity across the project's three build manifests."""

import argparse
from pathlib import Path
import re
import sys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    args = parser.parse_args()
    root = args.root.resolve()
    patterns = {
        "CMakeLists.txt": r"set\(DUCKDB_SOURCES\s+(.*?)\)",
        "config.m4": r"PHP_NEW_EXTENSION\(\[duckdb\],\s*\[(.*?)\]",
        "config.w32": r'EXTENSION\("duckdb",\s*"(.*?)"',
    }
    expected = {"duckdb.cpp"}
    expected.update(path.relative_to(root).as_posix() for path in (root / "src").rglob("*.cpp"))
    if not (root / "duckdb.cpp").is_file() or not (root / "src").is_dir():
        parser.error("--root must point to the php-duckdb repository")

    failed = False
    for filename, pattern in patterns.items():
        try:
            content = (root / filename).read_text()
        except OSError as error:
            print(f"{filename}: {error}", file=sys.stderr)
            failed = True
            continue

        if filename == "CMakeLists.txt":
            content = re.sub(r"#\[(=*)\[.*?\]\1\]", "", content, flags=re.DOTALL)
            content = re.sub(r"#[^\n]*", "", content)
        elif filename == "config.m4":
            content = re.sub(r"\bdnl\b[^\n]*", "", content)
        else:
            content = re.sub(r"/\*.*?\*/", "", content, flags=re.DOTALL)
            content = re.sub(r"//[^\n]*", "", content)

        match = re.search(pattern, content, re.DOTALL)
        if match is None:
            print(f"{filename}: source declaration not recognized", file=sys.stderr)
            failed = True
            continue

        declaration = match.group(1).replace("\\\\", "/").replace("\\", "/")
        sources = re.findall(r"[\w./-]+\.cpp\b", declaration)
        actual = set(sources)
        missing = sorted(expected - actual)
        stale = sorted(actual - expected)
        duplicates = sorted(source for source in actual if sources.count(source) > 1)
        if missing or stale or duplicates:
            failed = True
            for label, values in (("missing", missing), ("stale", stale), ("duplicate", duplicates)):
                if values:
                    print(f"{filename}: {label}: {', '.join(values)}", file=sys.stderr)
        else:
            print(f"{filename}: {len(actual)} native sources match")

    return int(failed)


if __name__ == "__main__":
    sys.exit(main())
