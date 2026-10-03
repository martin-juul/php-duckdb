"""Resource policy and hierarchy tests without depending on the test host's limits."""

import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


HELPER = Path(__file__).resolve().parents[1] / "jobs.py"
SPEC = importlib.util.spec_from_file_location("resource_jobs", HELPER)
jobs = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(jobs)


class WorkerPolicyTests(unittest.TestCase):
    def test_sixteen_cpus_use_twelve_workers_without_a_fixed_cap(self):
        self.assertEqual(jobs.choose_jobs("sdk", 16, 64 * jobs.GIB), 12)
        self.assertEqual(jobs.choose_jobs("sdk", 64, 256 * jobs.GIB), 48)

    def test_memory_budgets_and_floor(self):
        expected = {"sdk": 2, "extension": 12, "test": 12, "valgrind": 3}
        for profile, workers in expected.items():
            with self.subTest(profile=profile):
                self.assertEqual(jobs.choose_jobs(profile, 16, 8 * jobs.GIB), workers)

        self.assertEqual(jobs.choose_jobs("sdk", 16, 4 * jobs.GIB - 1), 1)
        self.assertEqual(jobs.choose_jobs("sdk", 0.5, 64 * jobs.GIB), 1)
        self.assertEqual(jobs.choose_jobs("sdk", 16, 0), 1)

    def test_unknown_memory_is_conservative(self):
        for profile in jobs.MEMORY_PER_WORKER:
            self.assertEqual(jobs.choose_jobs(profile, 128, None), 1)

    def test_cpuset_ranges_overlap_and_invalid_inputs(self):
        self.assertEqual(jobs.cpuset_count("0-3,2-5,8,10-11"), 9)
        for text in [None, "", "3-1", "-1", "x", "1-2-3", "0,"]:
            with self.subTest(text=text):
                self.assertIsNone(jobs.cpuset_count(text))


@unittest.skipIf(os.name == "nt", "POSIX SDK builder")
class BuilderOverrideTests(unittest.TestCase):
    def test_invalid_workers_fail_before_starting_build(self):
        builder = HELPER.parent.parent / "duckdb" / "build-sdk.sh"
        for value in ["0", "00", "01", "-1", "1.5", "invalid"]:
            with self.subTest(value=value):
                result = subprocess.run(
                    ["sh", str(builder), "--jobs", value],
                    text=True, capture_output=True, timeout=5,
                )
                self.assertEqual(result.returncode, 2)
                self.assertIn("Jobs must be a positive integer", result.stderr)


class CgroupTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="resource-cgroups-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def write(self, relative, value):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(str(value))

    def mount(self, kind="cgroup2", controllers="rw", root="/"):
        return "42 1 0:42 {} {} rw - {} none {}".format(
            root, self.root, kind, controllers
        )

    def test_v2_parent_limits_and_remaining_memory(self):
        self.write("parent/leaf/cpu.max", "800000 100000")
        self.write("parent/cpu.max", "250000 100000")
        self.write("cpu.max", "max 100000")
        self.write("parent/leaf/cpuset.cpus.effective", "0-5")
        self.write("parent/leaf/memory.max", 12 * jobs.GIB)
        self.write("parent/leaf/memory.current", 2 * jobs.GIB)
        self.write("parent/memory.max", 8 * jobs.GIB)
        self.write("parent/memory.current", 6 * jobs.GIB)
        self.write("memory.max", "max")

        cpu, memory, constraints, unknown = jobs.cgroup_limits(
            "0::/parent/leaf", self.mount()
        )
        self.assertEqual(min(cpu), 2.5)
        self.assertEqual(min(memory), 2 * jobs.GIB)
        self.assertFalse(unknown)
        self.assertEqual(len(constraints), 5)
        self.assertEqual(jobs.choose_jobs("sdk", min(cpu), min(memory)), 1)

    def test_v1_combined_controllers_parent_limit_and_unlimited_sentinel(self):
        self.write("parent/leaf/cpu.cfs_quota_us", -1)
        self.write("parent/leaf/cpu.cfs_period_us", 100000)
        self.write("parent/cpu.cfs_quota_us", 400000)
        self.write("parent/cpu.cfs_period_us", 100000)
        self.write("parent/leaf/cpuset.cpus", "0-7")
        self.write("parent/leaf/memory.limit_in_bytes", 9223372036854771712)
        self.write("parent/leaf/memory.usage_in_bytes", 1024)
        self.write("parent/memory.limit_in_bytes", 8 * jobs.GIB)
        self.write("parent/memory.usage_in_bytes", 4 * jobs.GIB)

        cpu, memory, _, unknown = jobs.cgroup_limits(
            "2:cpu,cpuacct,cpuset,memory:/parent/leaf",
            self.mount("cgroup", "rw,cpu,cpuacct,cpuset,memory"),
        )
        self.assertEqual(min(cpu), 4)
        self.assertEqual(memory, [4 * jobs.GIB])
        self.assertFalse(unknown)

    def test_namespaced_mount_root_and_escaped_path(self):
        self.write("cpu.max", "200000 100000")
        mount = self.mount(root="/host/container")
        cpu, _, _, _ = jobs.cgroup_limits("0::/", mount)
        self.assertEqual(cpu, [2])

        self.write("child/cpu.max", "100000 100000")
        cpu, _, _, _ = jobs.cgroup_limits("0::/host/container/child", mount)
        self.assertEqual(cpu, [1, 2])
        self.assertEqual(jobs.mount_path(r"/group\040name\134path"), "/group name\\path")

    def test_missing_usage_and_exhausted_memory(self):
        self.write("memory.max", jobs.GIB)
        _, memory, _, unknown = jobs.cgroup_limits("0::/", self.mount())
        self.assertEqual(memory, [])
        self.assertTrue(unknown)

        self.write("memory.current", 2 * jobs.GIB)
        _, memory, _, unknown = jobs.cgroup_limits("0::/", self.mount())
        self.assertEqual(memory, [0])
        self.assertFalse(unknown)

    def test_unrecognized_and_malformed_limits_do_not_crash(self):
        self.write("cpu.max", "broken")
        self.write("memory.max", "max")
        self.assertEqual(jobs.cgroup_limits("0::/", self.mount())[:2], ([], []))
        self.assertEqual(list(jobs.cgroup_directories("invalid", "invalid")), [])

        self.write("memory.max", "unrecognized")
        self.assertTrue(jobs.cgroup_limits("0::/", self.mount())[3])


class PlatformProbeTests(unittest.TestCase):
    def test_windows_active_processors_affinity_and_available_physical_memory(self):
        class Function:
            def __init__(self, callback):
                self.callback = callback

            def __call__(self, *arguments):
                return self.callback(*arguments)

        class Kernel:
            def __init__(self):
                self.GetActiveProcessorCount = Function(lambda group: 16)
                self.GetCurrentProcess = Function(lambda: 123)
                self.GetProcessAffinityMask = Function(self.affinity)
                self.GlobalMemoryStatusEx = Function(self.memory)

            def affinity(self, process, process_mask, system_mask):
                process_mask._obj.value = 0xff
                system_mask._obj.value = 0xffff
                return 1

            def memory(self, status):
                status._obj.available_physical = 8 * jobs.GIB
                return 1

        with patch.object(jobs.ctypes, "WinDLL", return_value=Kernel(), create=True):
            self.assertEqual(jobs.windows_resources(), (8, 8 * jobs.GIB))

        kernel = Kernel()
        kernel.GetActiveProcessorCount = Function(lambda group: 128)

        def full_group(process, process_mask, system_mask):
            process_mask._obj.value = (1 << 64) - 1
            system_mask._obj.value = (1 << 64) - 1
            return 1

        kernel.GetProcessAffinityMask = Function(full_group)
        version = SimpleNamespace(build=22000, product_type=1)
        with patch.object(jobs.ctypes, "WinDLL", return_value=kernel, create=True), \
                patch.object(jobs.sys, "getwindowsversion", return_value=version, create=True):
            self.assertEqual(jobs.windows_resources(), (128, 8 * jobs.GIB))

    def test_affinity_quota_and_host_memory_all_bound_selection(self):
        with patch.object(jobs.platform, "system", return_value="Linux"), \
                patch.object(jobs.os, "cpu_count", return_value=16), \
                patch.object(jobs.os, "sched_getaffinity", return_value={0, 1, 2, 3}, create=True), \
                patch.object(jobs, "available_memory", return_value=16 * jobs.GIB), \
                patch.object(jobs, "read_text", return_value=""), \
                patch.object(jobs, "cgroup_limits", return_value=([8], [4 * jobs.GIB], [], False)):
            result = jobs.probe("test")
            self.assertEqual(result["available_cpu"], 4)
            self.assertEqual(result["available_memory_bytes"], 4 * jobs.GIB)
            self.assertEqual(result["jobs"], 3)

    def test_mac_pages_exclude_overlapping_purgeable_count(self):
        text = (
            "Mach Virtual Memory Statistics: (page size of 4096 bytes)\n"
            "Pages free: 100.\nPages inactive: 200.\n"
            "Pages speculative: 50.\nPages purgeable: 180.\n"
        )
        with patch.object(jobs, "run_text", side_effect=[text, str(64 * jobs.GIB)]):
            self.assertEqual(jobs.available_memory("Darwin"), 350 * 4096)

    def test_solaris_available_pages_and_unknown_fallback(self):
        with patch.object(jobs.os, "sysconf", side_effect=[100, 8192]):
            self.assertEqual(jobs.available_memory("SunOS"), 819200)
        with patch.object(jobs.os, "sysconf", side_effect=ValueError):
            self.assertIsNone(jobs.available_memory("SunOS"))

    def test_real_probe_cli_plain_and_json(self):
        plain = subprocess.check_output(
            [sys.executable, str(HELPER), "--profile", "sdk"], text=True
        ).strip()
        self.assertTrue(plain.isdigit())
        self.assertGreaterEqual(int(plain), 1)

        result = json.loads(subprocess.check_output(
            [sys.executable, str(HELPER), "--profile", "sdk", "--json"], text=True
        ))
        self.assertEqual(result["profile"], "sdk")
        self.assertGreaterEqual(result["jobs"], 1)
        self.assertLessEqual(result["jobs"], max(1, int(result["available_cpu"] * 0.75)))


if __name__ == "__main__":
    unittest.main()
