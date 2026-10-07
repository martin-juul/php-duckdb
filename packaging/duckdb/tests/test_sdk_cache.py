#!/usr/bin/env python3
"""Exercise SDK identity and verified reuse without downloading or compiling DuckDB.

Run: python3 packaging/duckdb/tests/test_sdk_cache.py
"""

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]


class SDKCacheTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="duckdb-sdk-cache-test-")
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.builder = self.directory / "packaging/duckdb"
        self.builder.mkdir(parents=True)
        shutil.copy2(ROOT / "build-sdk.sh", self.builder / "build-sdk.sh")
        (self.builder / "fixture.patch").write_text(
            "--- a/fixture.txt\n+++ b/fixture.txt\n@@ -1 +1 @@\n-old\n+patched\n"
        )
        (self.builder / "patches").mkdir()
        (self.builder / "patches/arrow-geometry.patch").write_text(
            "--- a/arrow.txt\n+++ b/arrow.txt\n@@ -1 +1 @@\n-old-arrow\n+patched-arrow\n"
        )
        (self.builder / "patches/c-api-copy-functions.patch").write_text(
            "--- a/copy.txt\n+++ b/copy.txt\n@@ -1 +1 @@\n-old-copy\n+patched-copy\n"
        )
        source = self.directory / "source"
        (source / "src/include").mkdir(parents=True)
        (source / "src/include/duckdb.h").write_text("fixture C API header\n")
        (source / "LICENSE").write_text("fixture license\n")
        (source / "fixture.txt").write_text("old\n")
        (source / "arrow.txt").write_text("old-arrow\n")
        (source / "copy.txt").write_text("old-copy\n")
        self.archive = self.directory / "source.tar.gz"
        with tarfile.open(self.archive, "w:gz") as archive:
            archive.add(source, arcname="duckdb-fixture")
        self.manifest = {
            "version": "1.5.6",
            "commit": "069cc9abcdef012345678901234567890123456789",
            "url": "https://invalid.example/never-download.tar.gz",
            "sha256": hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            "patch": "fixture.patch",
        }
        self.write_manifest()
        self.prefix = self.directory / "sdk"
        self.work = self.directory / "work"
        self.cache = self.directory / "cache"
        self.log = self.directory / "tools.log"
        self.tools = self.directory / "bin"
        self.tools.mkdir()
        self.tool("uname", """#!/bin/sh
case "$1" in
    -s) echo "${SDK_TEST_PLATFORM:-Linux}" ;;
    -m) echo "${SDK_TEST_ARCH:-x86_64}" ;;
    -r) echo "${SDK_TEST_KERNEL:-never-part-of-identity}" ;;
    -a) echo "fixture ${SDK_TEST_PLATFORM:-Linux} x86_64" ;;
    *) exit 2 ;;
esac
""")
        self.tool("cc", """#!/bin/sh
echo "fixture C compiler ${SDK_TEST_CC_VERSION:-1}"
""")
        self.tool("c++", """#!/bin/sh
if [ "$1" = -dumpmachine ]; then
    echo "${SDK_TEST_TARGET:-x86_64-linux-gnu}"
else
    echo "fixture C++ compiler ${SDK_TEST_CXX_VERSION:-1}"
fi
""")
        self.tool("ldd", """#!/bin/sh
echo "fixture libc ${SDK_TEST_LIBC:-1}"
""")
        self.tool("cat", """#!/bin/sh
if [ "$1" = /etc/os-release ]; then
    echo "ID=fixture; VERSION_ID=${SDK_TEST_OS_VERSION:-1}"
else
    exec /bin/cat "$@"
fi
""")
        self.tool("sw_vers", "#!/bin/sh\necho \"fixture macOS ${SDK_TEST_MAC_VERSION:-1}\"\n")
        self.tool("xcrun", "#!/bin/sh\necho \"${SDK_TEST_MAC_SDK:-1}\"\n")
        self.tool("curl", "#!/bin/sh\necho 'Unexpected download' >&2\nexit 90\n")
        self.tool("make", "#!/bin/sh\nexit 90\n")
        self.tool("patch", """#!/usr/bin/env python3
from pathlib import Path
import sys
assert sys.argv[1:] == ['-p1', '-F', '0', '-t']
patch = sys.stdin.read()
if patch.startswith('--- a/fixture.txt'):
    assert patch == '--- a/fixture.txt\\n+++ b/fixture.txt\\n@@ -1 +1 @@\\n-old\\n+patched\\n'
    assert Path('fixture.txt').read_text() == 'old\\n'
    Path('fixture.txt').write_text('patched\\n')
elif patch.startswith('--- a/arrow.txt'):
    assert patch == '--- a/arrow.txt\\n+++ b/arrow.txt\\n@@ -1 +1 @@\\n-old-arrow\\n+patched-arrow\\n'
    assert Path('fixture.txt').read_text() == 'patched\\n'
    assert Path('arrow.txt').read_text() == 'old-arrow\\n'
    Path('arrow.txt').write_text('patched-arrow\\n')
else:
    assert patch == '--- a/copy.txt\\n+++ b/copy.txt\\n@@ -1 +1 @@\\n-old-copy\\n+patched-copy\\n'
    assert Path('arrow.txt').read_text() == 'patched-arrow\\n'
    assert Path('copy.txt').read_text() == 'old-copy\\n'
    Path('copy.txt').write_text('patched-copy\\n')
""")
        self.tool("cmake", """#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys
args = sys.argv[1:]
if args == ['--version']:
    print('fixture cmake ' + os.environ.get('SDK_TEST_CMAKE_VERSION', '1'))
    sys.exit(0)
with open(os.environ['SDK_TEST_LOG'], 'a') as log:
    log.write(json.dumps(args) + '\\n')
if args[0] == '--build':
    if os.environ.get('SDK_TEST_FAIL_BUILD') == '1':
        sys.exit(91)
    build = Path(args[1]) / 'src'
    build.mkdir(parents=True, exist_ok=True)
    name = 'libduckdb.dylib' if os.environ.get('SDK_TEST_PLATFORM') == 'Darwin' else 'libduckdb.so'
    (build / name).write_bytes(b'fixture compiled library')
else:
    source = Path(args[args.index('-S') + 1])
    assert (source / 'fixture.txt').read_text() == 'patched\\n'
    assert (source / 'arrow.txt').read_text() == 'patched-arrow\\n'
    assert (source / 'copy.txt').read_text() == 'patched-copy\\n'
""")
        self.env = os.environ.copy()
        for variable in [
            "CC", "CXX", "CFLAGS", "CXXFLAGS", "LDFLAGS",
            "MACOSX_DEPLOYMENT_TARGET", "DUCKDB_OSX_ARCHITECTURES",
            "DUCKDB_DISABLE_UNITY", "DUCKDB_BUILD_JOBS", "DUCKDB_SDK_CACHE_DIR",
            "DUCKDB_SOURCE_ARCHIVE", "DUCKDB_BUILD_DIR", "DUCKDB_SDK_PREFIX",
        ]:
            self.env.pop(variable, None)
        self.env.update({
            "PATH": str(self.tools) + os.pathsep + os.environ["PATH"],
            "DUCKDB_SOURCE_ARCHIVE": str(self.archive),
            "SDK_TEST_LOG": str(self.log),
        })

    def tool(self, name, content):
        path = self.tools / name
        path.write_text(content)
        path.chmod(0o755)

    def write_manifest(self):
        (self.builder / "source.json").write_text(json.dumps(self.manifest))

    def run_builder(self, *arguments, changes=None, success=True):
        environment = self.env | (changes or {})
        result = subprocess.run(
            ["sh", str(self.builder / "build-sdk.sh"), "--prefix", str(self.prefix),
             "--work-dir", str(self.work), *arguments],
            env=environment, text=True, capture_output=True, timeout=30,
        )
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def fingerprint(self, changes=None):
        value = self.run_builder("--fingerprint", changes=changes).stdout.strip()
        self.assertRegex(value, r"^[a-f0-9]{64}$")
        return value

    def build(self, cached=False, **changes):
        if cached:
            changes["DUCKDB_SDK_CACHE_DIR"] = str(self.cache)
        return self.run_builder("--jobs", "2", changes=changes)

    def builds(self):
        if not self.log.exists():
            return 0
        return sum(json.loads(line)[0] == "--build" for line in self.log.read_text().splitlines())

    def test_fingerprint_is_metadata_only_and_matches_built_sdk(self):
        fingerprint = self.fingerprint()
        self.assertFalse(self.work.exists())
        self.assertFalse(self.prefix.exists())
        self.assertFalse(self.log.exists())
        self.build()
        metadata = (self.prefix / "share/duckdb-sdk/build.txt").read_bytes()
        self.assertEqual(fingerprint, hashlib.sha256(metadata).hexdigest())
        self.assertEqual(self.builds(), 1)
        self.assertIn("Reusing verified", self.build().stdout)
        self.assertEqual(self.builds(), 1)

    def test_jobs_kernel_and_prefix_do_not_change_identity(self):
        fingerprint = self.fingerprint()
        self.assertEqual(fingerprint, self.fingerprint({"DUCKDB_BUILD_JOBS": "999"}))
        self.assertEqual(fingerprint, self.fingerprint({"SDK_TEST_KERNEL": "another-kernel"}))
        self.prefix = self.directory / "another-prefix"
        self.work = self.directory / "another-workdir"
        self.assertEqual(fingerprint, self.fingerprint())

    def test_environment_and_options_invalidate_identity(self):
        fingerprint = self.fingerprint()
        for variable in [
            "SDK_TEST_ARCH", "SDK_TEST_CC_VERSION", "SDK_TEST_CXX_VERSION",
            "SDK_TEST_TARGET", "SDK_TEST_CMAKE_VERSION", "SDK_TEST_LIBC",
            "CFLAGS", "CXXFLAGS", "LDFLAGS",
            "DUCKDB_OSX_ARCHITECTURES", "MACOSX_DEPLOYMENT_TARGET",
        ]:
            with self.subTest(variable=variable):
                self.assertNotEqual(fingerprint, self.fingerprint({variable: "changed"}))
        if Path("/etc/os-release").exists():
            self.assertNotEqual(fingerprint, self.fingerprint({"SDK_TEST_OS_VERSION": "2"}))
        self.assertNotEqual(fingerprint, self.fingerprint({"DUCKDB_DISABLE_UNITY": "ON"}))
        mac = {"SDK_TEST_PLATFORM": "Darwin"}
        self.assertNotEqual(self.fingerprint(mac), self.fingerprint(mac | {"SDK_TEST_MAC_SDK": "2"}))
        self.assertNotEqual(self.fingerprint(mac), self.fingerprint(mac | {"SDK_TEST_MAC_VERSION": "2"}))

    def test_source_patch_and_builder_invalidate_identity(self):
        fingerprint = self.fingerprint()
        self.manifest["commit"] = "changed"
        self.write_manifest()
        self.assertNotEqual(fingerprint, self.fingerprint())
        fingerprint = self.fingerprint()
        with (self.builder / "fixture.patch").open("a") as patch:
            patch.write("\n")
        self.assertNotEqual(fingerprint, self.fingerprint())
        for name in ["arrow-geometry.patch", "c-api-copy-functions.patch"]:
            fingerprint = self.fingerprint()
            with (self.builder / "patches" / name).open("a") as patch:
                patch.write("\n")
            self.assertNotEqual(fingerprint, self.fingerprint())
        fingerprint = self.fingerprint()
        with (self.builder / "build-sdk.sh").open("a") as builder:
            builder.write("\n# changed implementation\n")
        self.assertNotEqual(fingerprint, self.fingerprint())

    def test_existing_prefix_artifact_validation_forces_rebuild(self):
        self.build()
        (self.prefix / "lib/libduckdb.so").write_bytes(b"corrupt")
        self.build()
        self.assertEqual(self.builds(), 2)
        self.assertEqual((self.prefix / "lib/libduckdb.so").read_bytes(), b"fixture compiled library")

    def test_content_addressed_cache_restores_only_sdk_files(self):
        self.build(cached=True)
        self.assertEqual(self.builds(), 1)
        fingerprint = self.fingerprint()
        entry = self.cache / fingerprint
        self.assertTrue((entry / "share/duckdb-sdk/artifacts.json").is_file())
        self.assertEqual(len(list(entry.rglob("*.*"))), 9)
        self.assertEqual([path.name for path in self.cache.iterdir()], [fingerprint])
        shutil.rmtree(self.prefix)
        self.assertIn("Restored verified", self.build(cached=True).stdout)
        self.assertEqual(self.builds(), 1)
        self.assertEqual((self.prefix / "lib/libduckdb.so").read_bytes(), b"fixture compiled library")
        shutil.rmtree(self.prefix)
        self.build(cached=True, CXXFLAGS="-Ddifferent")
        self.assertEqual(self.builds(), 2)
        self.assertEqual(len(list(self.cache.iterdir())), 2)

    def test_corrupt_artifacts_and_metadata_force_rebuild(self):
        self.build(cached=True)
        for name in [
            "lib/libduckdb.so", "include/duckdb.h", "share/duckdb-sdk/LICENSE.duckdb",
            "share/duckdb-sdk/source.json", "share/duckdb-sdk/nullable-bitpacking.patch",
            "share/duckdb-sdk/arrow-geometry.patch", "share/duckdb-sdk/c-api-copy-functions.patch",
            "share/duckdb-sdk/build.txt", "share/duckdb-sdk/artifacts.json",
        ]:
            with self.subTest(artifact=name):
                entry = self.cache / self.fingerprint()
                (entry / name).write_bytes(b"corrupt")
                shutil.rmtree(self.prefix)
                count = self.builds()
                self.build(cached=True)
                self.assertEqual(self.builds(), count + 1)
                self.assertNotEqual((entry / name).read_bytes(), b"corrupt")

    def test_missing_patch_rejected_before_fingerprint_or_build(self):
        for name in ["arrow-geometry.patch", "c-api-copy-functions.patch"]:
            with self.subTest(patch=name):
                patch = self.builder / "patches" / name
                content = patch.read_text()
                patch.unlink()
                result = self.run_builder("--fingerprint", success=False)
                self.assertIn("Pinned DuckDB patch missing", result.stderr)
                self.assertFalse(self.log.exists())
                patch.write_text(content)

    def test_cache_missing_patch_or_hash_is_rejected(self):
        self.build(cached=True)
        for name, remove_hash in [
            (name, remove_hash)
            for name in ["arrow-geometry.patch", "c-api-copy-functions.patch"]
            for remove_hash in [False, True]
        ]:
            with self.subTest(patch=name, remove_hash=remove_hash):
                entry = self.cache / self.fingerprint()
                artifact = "share/duckdb-sdk/" + name
                if remove_hash:
                    manifest = entry / "share/duckdb-sdk/artifacts.json"
                    pins = json.loads(manifest.read_text())
                    del pins[artifact]
                    manifest.write_text(json.dumps(pins))
                else:
                    (entry / artifact).unlink()
                shutil.rmtree(self.prefix)
                count = self.builds()
                self.build(cached=True)
                self.assertEqual(self.builds(), count + 1)
                self.assertTrue((entry / artifact).is_file())

    def test_solaris_recipe_applies_and_records_all_patches(self):
        solaris = self.directory / "packaging/solaris"
        solaris.mkdir()
        shutil.copy2(ROOT.parent / "solaris/build-sdk.sh", solaris / "build-sdk.sh")
        shutil.copy2(self.tools / "patch", self.tools / "gpatch")
        self.tool("gtar", "#!/bin/sh\nexec tar \"$@\"\n")
        self.tool("gmake", "#!/bin/sh\nexit 90\n")
        result = subprocess.run(
            ["sh", str(solaris / "build-sdk.sh")],
            env=self.env | {
                "SDK_TEST_PLATFORM": "SunOS", "CC": "cc", "CXX": "c++",
                "DUCKDB_SDK_PREFIX": str(self.prefix), "DUCKDB_BUILD_DIR": str(self.work),
                "DUCKDB_BUILD_JOBS": "2",
            }, text=True, capture_output=True, timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        metadata = self.prefix / "share/duckdb-sdk"
        pins = json.loads((metadata / "artifacts.json").read_text())
        for patch in ["nullable-bitpacking.patch", "arrow-geometry.patch", "c-api-copy-functions.patch"]:
            artifact = "share/duckdb-sdk/" + patch
            self.assertIn(artifact, pins)
            self.assertEqual(pins[artifact], hashlib.sha256((self.prefix / artifact).read_bytes()).hexdigest())
        self.assertIn("arrow_patch_sha256=", (metadata / "build.txt").read_text())
        self.assertIn("copy_function_patch_sha256=", (metadata / "build.txt").read_text())
        self.assertEqual(self.builds(), 1)

    def test_optimized_python_still_checks_cache_artifacts(self):
        self.build(cached=True)
        entry = self.cache / self.fingerprint()
        (entry / "lib/libduckdb.so").write_bytes(b"corrupt")
        shutil.rmtree(self.prefix)
        self.build(cached=True, PYTHONOPTIMIZE="1")
        self.assertEqual(self.builds(), 2)
        self.assertEqual((self.prefix / "lib/libduckdb.so").read_bytes(), b"fixture compiled library")

    def test_corrupt_cache_is_not_used_when_rebuild_fails(self):
        self.build(cached=True)
        entry = self.cache / self.fingerprint()
        (entry / "lib/libduckdb.so").write_bytes(b"poisoned")
        shutil.rmtree(self.prefix)
        self.run_builder("--jobs", "2", changes={
            "DUCKDB_SDK_CACHE_DIR": str(self.cache), "SDK_TEST_FAIL_BUILD": "1",
        }, success=False)
        self.assertFalse((self.prefix / "lib/libduckdb.so").exists())


if __name__ == "__main__":
    unittest.main()
