"""Run without dependencies: python3 .github/tests/test_docker_manifest.py."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "docker-manifest.sh"
TAGS = SCRIPT.with_name("docker-tags.sh")
IMAGE = "ghcr.io/martin-juul/php-duckdb"


class DockerManifestTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="docker manifest ")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.digests = self.root / "digests"
        self.digests.mkdir()
        for architecture, digit in (("amd64", "a"), ("arm64", "b")):
            (self.digests / (architecture + ".digest")).write_text("sha256:" + digit * 64 + "\n")
        docker = self.root / "docker"
        docker.write_text("#!/usr/bin/env python3\nimport json, os, sys\n"
                          "with open(os.environ['DOCKER_CALLS'], 'a') as f:\n"
                          "    f.write(json.dumps(sys.argv[1:]) + '\\n')\n"
                          "sys.exit(int(os.environ.get('DOCKER_EXIT', '0')))\n")
        docker.chmod(0o755)
        self.calls = self.root / "calls.jsonl"
        self.env = dict(os.environ, PATH=str(self.root) + os.pathsep + os.environ["PATH"],
                        DOCKER_CALLS=str(self.calls), IMAGE=IMAGE.upper(), PHP_VERSION="8.5",
                        VARIANT="", DEFAULT_PHP="8.5", GITHUB_REF="refs/heads/master")

    def run_manifest(self):
        return subprocess.run(["bash", str(SCRIPT), str(self.digests)], env=self.env,
                              capture_output=True, text=True)

    def assert_rejected(self):
        result = self.run_manifest()
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertFalse(self.calls.exists(), "Invalid inputs reached the registry command")

    def test_all_release_tags_use_both_native_digests(self):
        for ref in ("refs/heads/master", "refs/tags/v1.3.1", "refs/tags/1.4.0-rc.1"):
            for variant, versions in (("", ("8.2", "8.3", "8.4", "8.5")),
                                      ("frankenphp", ("8.4", "8.5"))):
                for php in versions:
                    with self.subTest(ref=ref, variant=variant, php=php):
                        self.env.update(GITHUB_REF=ref, VARIANT=variant, PHP_VERSION=php)
                        result = self.run_manifest()
                        self.assertEqual(result.returncode, 0, result.stderr)
                        command = json.loads(self.calls.read_text().splitlines()[-1])
                        tags = subprocess.check_output(["bash", str(TAGS)], env=self.env,
                                                       text=True).strip().split(",")
                        expected = ["buildx", "imagetools", "create"]
                        for tag in tags:
                            expected.extend(("--tag", tag))
                        expected.extend(IMAGE + "@sha256:" + digit * 64 for digit in ("a", "b"))
                        self.assertEqual(command, expected)

    def test_missing_architecture_never_publishes(self):
        (self.digests / "arm64.digest").unlink()
        self.assert_rejected()

    def test_unexpected_artifact_never_publishes(self):
        (self.digests / ".extra").write_text("unexpected")
        self.assert_rejected()

    def test_wrong_architecture_name_never_publishes(self):
        (self.digests / "arm64.digest").rename(self.digests / "other.digest")
        self.assert_rejected()

    def test_malformed_digest_never_publishes(self):
        for value in ("", "sha256:abc", "sha256:" + "A" * 64,
                      "sha256:" + "a" * 64 + "\nsha256:" + "b" * 64, "--tag=unexpected"):
            with self.subTest(value=value):
                (self.digests / "arm64.digest").write_text(value)
                self.assert_rejected()

    def test_duplicate_digest_never_publishes(self):
        (self.digests / "arm64.digest").write_text("sha256:" + "a" * 64)
        self.assert_rejected()

    def test_bad_release_tag_never_publishes(self):
        self.env["GITHUB_REF"] = "refs/tags/invalid"
        self.assert_rejected()

    def test_registry_failure_is_propagated(self):
        self.env["DOCKER_EXIT"] = "19"
        self.assertEqual(self.run_manifest().returncode, 19)


if __name__ == "__main__":
    unittest.main()
