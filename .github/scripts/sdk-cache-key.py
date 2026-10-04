#!/usr/bin/env python3
"""Identify the package environment; builders verify each cached SDK again."""

import hashlib
import os
from pathlib import Path
import re
import subprocess


def command(*args):
    return subprocess.check_output(args, text=True).strip()


def amazon_build_flags(flags):
    # Match the recipe: GCC's -flto=auto ignores resource limits, so the
    # effective compiler/linker flags depend on the SDK worker budget.
    jobs = os.environ.get("DUCKDB_BUILD_JOBS") or command(
        "python3", "packaging/resources/jobs.py", "--profile", "sdk"
    )
    if not re.fullmatch(r"[1-9][0-9]*", jobs):
        raise ValueError("DUCKDB_BUILD_JOBS must be a positive integer")
    return flags.replace("-flto=auto", f"-flto={jobs}")


def cache_key(target, cache_path):
    recipe = Path("packaging") / target
    if target not in {
        "opensuse", "fedora", "almalinux", "amazonlinux", "debian", "debian-trixie", "ubuntu", "macos", "windows"
    }:
        raise ValueError(f"Unknown SDK packaging target: {target}")

    if target == "windows":
        fingerprint = command(
            "pwsh", "-NoProfile", "-File", "packaging/duckdb/build-sdk.ps1",
            "-Prefix", str(cache_path / "probe-sdk"),
            "-WorkDirectory", str(cache_path.parent / "sdk-cache-probe"),
            "-Fingerprint",
        )
    else:
        fingerprint = command("sh", "packaging/duckdb/build-sdk.sh", "--fingerprint")

    fingerprints = re.findall(r"^[a-f0-9]{64}$", fingerprint, re.MULTILINE)
    if len(fingerprints) != 1:
        raise ValueError("SDK builder did not return exactly one fingerprint")

    identity = [fingerprints[0]]
    # Package tools supply flags inside rpmbuild/debhelper. Include their
    # expanded defaults and recipes in the outer key; the builder checks the
    # actual flags again when restoring a content-addressed SDK entry.
    if target in {"opensuse", "fedora", "almalinux", "amazonlinux"}:
        flags = command("rpm", "--eval", "%{?set_build_flags}")
        if target == "amazonlinux":
            flags = amazon_build_flags(flags)
        identity.extend([command("rpm", "--version"), flags])
        recipes = sorted(recipe.glob("*.spec"))
    elif target in {"debian", "debian-trixie", "ubuntu"}:
        identity.append(command("dpkg-buildflags", "--export=sh"))
        recipes = [recipe / "rules"]
    else:
        recipes = [recipe / ("build.ps1" if target == "windows" else "build.sh")]

    for path in recipes:
        identity.append(path.as_posix())
        identity.append(hashlib.sha256(path.read_bytes()).hexdigest())

    digest = hashlib.sha256("\n".join(identity).encode()).hexdigest()
    return f"duckdb-package-sdk-v1-{target}-{digest}"


def main():
    cache_path = Path(os.environ["SDK_CACHE_PATH"])
    key = cache_key(os.environ["SDK_CACHE_TARGET"], cache_path)
    with open(os.environ["GITHUB_OUTPUT"], "a") as output:
        output.write(f"key={key}\npath={cache_path.as_posix()}\n")
    with open(os.environ["GITHUB_ENV"], "a") as output:
        output.write(f"DUCKDB_SDK_CACHE_DIR={cache_path.as_posix()}\n")


if __name__ == "__main__":
    main()
