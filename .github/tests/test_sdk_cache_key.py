"""Check packaging SDK key stability without invoking native build tooling."""

import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "scripts/sdk-cache-key.py"
SPEC = importlib.util.spec_from_file_location("sdk_cache_key", SCRIPT)
SDK_CACHE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SDK_CACHE)


class SdkCacheKeyTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.previous_directory = Path.cwd()
        os.chdir(self.temporary.name)
        self.addCleanup(os.chdir, self.previous_directory)
        self.cache_path = Path(self.temporary.name) / "cache"
        self.fingerprint = "a" * 64
        self.flags = "CXXFLAGS='-O2'"
        self.rpm_version = "RPM version 4.20"
        self.commands = []
        for target in ("opensuse", "fedora", "almalinux", "debian-trixie", "ubuntu", "macos", "windows"):
            recipe = Path("packaging") / target
            recipe.mkdir(parents=True)
            if target in {"opensuse", "fedora", "almalinux"}:
                name = "php-duckdb.spec"
            elif target in {"debian-trixie", "ubuntu"}:
                name = "rules"
            elif target == "windows":
                name = "build.ps1"
            else:
                name = "build.sh"
            (recipe / name).write_text("recipe\n")
        command_patch = patch.object(SDK_CACHE, "command", side_effect=self.command)
        command_patch.start()
        self.addCleanup(command_patch.stop)

    def command(self, *arguments):
        self.commands.append(arguments)
        if arguments[0] in {"pwsh", "sh"}:
            return "SDK workers: 2\n" + self.fingerprint
        if arguments[:2] == ("rpm", "--version"):
            return self.rpm_version
        if arguments[:2] == ("rpm", "--eval") or arguments[0] == "dpkg-buildflags":
            return self.flags
        self.fail(f"Unexpected command: {arguments}")

    def key(self, target):
        return SDK_CACHE.cache_key(target, self.cache_path)

    def test_keys_ignore_php_jobs_and_commit(self):
        for target in ("opensuse", "fedora", "almalinux", "debian-trixie", "ubuntu", "macos", "windows"):
            with self.subTest(target=target):
                initial = self.key(target)
                with patch.dict(os.environ, {
                    "PHP_VERSION": "8.5", "PHP_TS": "nts", "DUCKDB_BUILD_JOBS": "16",
                    "DUCKDB_JOBS": "8", "GITHUB_SHA": "b" * 40,
                }):
                    self.assertEqual(initial, self.key(target))
                self.assertRegex(initial, rf"^duckdb-package-sdk-v1-{target}-[a-f0-9]{{64}}$")

    def test_toolchain_fingerprint_invalidates_every_target(self):
        for target in ("opensuse", "fedora", "almalinux", "debian-trixie", "ubuntu", "macos", "windows"):
            with self.subTest(target=target):
                self.fingerprint = "a" * 64
                initial = self.key(target)
                self.fingerprint = "b" * 64
                self.assertNotEqual(initial, self.key(target))

    def test_package_flags_invalidate_rpm_and_deb_keys(self):
        for target in ("opensuse", "fedora", "almalinux", "debian-trixie", "ubuntu"):
            with self.subTest(target=target):
                self.flags = "CXXFLAGS='-O2'"
                initial = self.key(target)
                self.flags = "CXXFLAGS='-O3 -flto'"
                self.assertNotEqual(initial, self.key(target))

    def test_rpm_tool_version_invalidates_key(self):
        initial = self.key("fedora")
        self.rpm_version = "RPM version 4.21"
        self.assertNotEqual(initial, self.key("fedora"))

    def test_recipe_changes_invalidate_every_target(self):
        for target in ("opensuse", "fedora", "almalinux", "debian-trixie", "ubuntu", "macos", "windows"):
            with self.subTest(target=target):
                initial = self.key(target)
                recipe = next((Path("packaging") / target).iterdir())
                recipe.write_text("updated recipe\n")
                self.assertNotEqual(initial, self.key(target))

    def test_windows_fingerprint_uses_native_builder_and_probe_paths(self):
        self.key("windows")
        invocation = self.commands[0]
        self.assertEqual(invocation[:4], ("pwsh", "-NoProfile", "-File", "packaging/duckdb/build-sdk.ps1"))
        self.assertIn(str(self.cache_path / "probe-sdk"), invocation)
        self.assertIn(str(self.cache_path.parent / "sdk-cache-probe"), invocation)
        self.assertEqual(invocation[-1], "-Fingerprint")

    def test_cache_location_does_not_change_identity(self):
        for target in ("fedora", "macos", "windows"):
            with self.subTest(target=target):
                self.assertEqual(self.key(target), SDK_CACHE.cache_key(target, Path("another-cache")))

    def test_unknown_target_fails_before_commands(self):
        for target in ("debian", "../fedora", "", "solaris"):
            with self.subTest(target=target):
                with self.assertRaisesRegex(ValueError, "Unknown SDK packaging target"):
                    self.key(target)
        self.assertEqual(self.commands, [])

    def test_missing_or_ambiguous_fingerprints_fail(self):
        for fingerprint in ("", "A" * 64, "a" * 63, "a" * 64 + "\n" + "b" * 64):
            with self.subTest(fingerprint=fingerprint):
                self.fingerprint = fingerprint
                with self.assertRaisesRegex(ValueError, "exactly one fingerprint"):
                    self.key("macos")

    def test_fingerprint_process_failure_propagates(self):
        failure = subprocess.CalledProcessError(1, ["sh", "build-sdk.sh"])
        with patch.object(SDK_CACHE, "command", side_effect=failure):
            with self.assertRaises(subprocess.CalledProcessError):
                self.key("macos")


if __name__ == "__main__":
    unittest.main()
