"""Run without dependencies: python3 .github/tests/test_docker_tags.py."""
import os
from pathlib import Path
import subprocess
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "docker-tags.sh"
IMAGE = "ghcr.io/martin-juul/php-duckdb"


def generate(ref, php="8.5", variant="", default="8.5"):
    env = dict(os.environ, IMAGE=IMAGE.upper(), PHP_VERSION=php,
               DEFAULT_PHP=default, VARIANT=variant, GITHUB_REF=ref)
    return subprocess.run(["bash", str(SCRIPT)], env=env,
                          capture_output=True, text=True)


class DockerTagsTest(unittest.TestCase):
    def tags(self, ref, php="8.5", variant="", default="8.5"):
        result = generate(ref, php, variant, default)
        self.assertEqual(result.returncode, 0, result.stderr)
        tags = result.stdout.strip().split(",")
        self.assertEqual(len(tags), len(set(tags)))
        self.assertTrue(all(tag.startswith(IMAGE + ":") for tag in tags))
        return {tag[len(IMAGE) + 1:] for tag in tags}

    def test_branch_and_pull_request_aliases(self):
        for ref in ("refs/heads/master", "refs/pull/7/merge"):
            for php in ("8.2", "8.3", "8.4", "8.5"):
                with self.subTest(ref=ref, php=php):
                    expected = {php, "php" + php}
                    if php == "8.5":
                        expected.add("latest")
                    self.assertEqual(self.tags(ref, php), expected)

    def test_release_matrix_has_no_shared_alias_owners(self):
        for ref in ("refs/tags/v1.3.1", "refs/tags/1.3.1"):
            all_tags = set()
            for variant, versions in (("", ("8.2", "8.3", "8.4", "8.5")),
                                      ("frankenphp", ("8.4", "8.5"))):
                for php in versions:
                    with self.subTest(ref=ref, php=php, variant=variant):
                        suffix = "-frankenphp" if variant else ""
                        expected = {php, "php" + php}
                        expected.update(v + "-php" + php for v in ("1.3.1", "1.3", "1"))
                        if php == "8.5":
                            expected.update(("latest", "1.3.1", "1.3", "1"))
                        expected = {tag + suffix for tag in expected}
                        actual = self.tags(ref, php, variant)
                        self.assertEqual(actual, expected)
                        self.assertFalse(all_tags.intersection(actual))
                        all_tags.update(actual)

    def test_prereleases_do_not_overwrite_stable_aliases(self):
        for ref in ("refs/tags/v1.4.0-rc.1", "refs/tags/1.4.0-rc.1"):
            for variant in ("", "frankenphp"):
                for php in ("8.4", "8.5"):
                    with self.subTest(ref=ref, php=php, variant=variant):
                        expected = {"1.4.0-rc.1-php" + php}
                        if php == "8.5":
                            expected.add("1.4.0-rc.1")
                        suffix = "-frankenphp" if variant else ""
                        self.assertEqual(self.tags(ref, php, variant),
                                         {tag + suffix for tag in expected})

    def test_prerelease_hyphens(self):
        self.assertEqual(self.tags("refs/tags/v1.4.0-rc--1"),
                         {"1.4.0-rc--1", "1.4.0-rc--1-php8.5"})

    def test_default_php_is_configurable(self):
        tags = self.tags("refs/tags/v1.3.1", php="8.4", default="8.4")
        self.assertIn("latest", tags)
        self.assertIn("1.3.1", tags)
        tags = self.tags("refs/tags/v1.3.1", php="8.5", default="8.4")
        self.assertNotIn("latest", tags)
        self.assertNotIn("1.3.1", tags)

    def test_bad_release_refs_fail_without_output(self):
        for tag in ("version", "1.3", "01.3.1", "1.3.1+build", "1.3.1-",
                    "1.3.1-rc..1", "1.3.1-æ", "1.3.1;echo bad", "1.3.1-" + "x" * 130):
            with self.subTest(tag=tag):
                result = generate("refs/tags/" + tag)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")

    def test_bad_matrix_inputs_fail(self):
        for kwargs in ({"php": "8.5,other"}, {"variant": "unknown"}):
            with self.subTest(**kwargs):
                result = generate("refs/heads/master", **kwargs)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
