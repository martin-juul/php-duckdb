#!/usr/bin/env python3
"""Choose worker counts from available CPU and RAM, including cgroup limits."""

import argparse
import ctypes
import json
import math
import os
from pathlib import Path
import platform
import re
import subprocess
import sys


GIB = 1024 ** 3
MEMORY_PER_WORKER = {
    "sdk": 3 * GIB,
    "extension": GIB // 2,
    "test": GIB // 2,
    "valgrind": 2 * GIB,
}
RESOURCE_FRACTION = 0.75


def read_text(path):
    try:
        return Path(path).read_text().strip()
    except (OSError, UnicodeError):
        return None


def nonnegative_integer(text):
    try:
        value = int(text)
        return value if value >= 0 else None
    except (TypeError, ValueError):
        return None


def run_text(arguments):
    try:
        return subprocess.check_output(
            arguments, universal_newlines=True, stderr=subprocess.DEVNULL, timeout=5
        ).strip()
    except (OSError, subprocess.SubprocessError):
        return None


def cpuset_count(text):
    """Count the union of CPU ranges without allocating a set per CPU."""
    if not text:
        return None

    intervals = []
    try:
        for entry in text.split(","):
            endpoints = entry.split("-")
            if len(endpoints) > 2:
                return None

            first = int(endpoints[0])
            last = int(endpoints[-1])
            if first < 0 or last < first:
                return None

            intervals.append((first, last))
    except ValueError:
        return None

    total = 0
    previous = -1
    for first, last in sorted(intervals):
        total += max(0, last - max(first, previous + 1) + 1)
        previous = max(previous, last)

    return total or None


def mount_path(text):
    return re.sub(r"\\([0-7]{3})", lambda match: chr(int(match[1], 8)), text)


def cgroup_directories(cgroup_text, mountinfo_text):
    """Yield each visible cgroup and its ancestors up to its mount root."""
    memberships = []
    for line in (cgroup_text or "").splitlines():
        fields = line.split(":", 2)
        if len(fields) == 3:
            memberships.append((set(filter(None, fields[1].split(","))), fields[2]))

    visited = set()
    for line in (mountinfo_text or "").splitlines():
        fields = line.split()
        try:
            separator = fields.index("-")
            kind = fields[separator + 1]
            controllers = set(fields[separator + 3].split(","))
            root = mount_path(fields[3])
            mount = Path(mount_path(fields[4]))
        except (ValueError, IndexError):
            continue

        if kind not in {"cgroup", "cgroup2"}:
            continue

        for membership_controllers, membership in memberships:
            if kind == "cgroup2" and membership_controllers:
                continue
            if kind == "cgroup" and not (controllers & membership_controllers):
                continue

            membership = os.path.normpath("/" + membership.lstrip("/"))
            root = os.path.normpath(root)
            if membership == root:
                relative = ""
            elif membership.startswith(root.rstrip("/") + "/"):
                relative = membership[len(root.rstrip("/")) + 1:]
            elif membership == "/":
                # A cgroup namespace can expose its membership as / while the
                # mount still records the enclosing host cgroup as its root.
                relative = ""
            else:
                continue

            directory = mount / relative
            while True:
                key = (kind, str(directory))
                if key not in visited:
                    visited.add(key)
                    yield kind, directory, controllers

                if directory == mount:
                    break
                directory = directory.parent


def cgroup_limits(cgroup_text, mountinfo_text):
    cpu_limits = []
    memory_limits = []
    constraints = []
    unknown_remaining_memory = False

    def cpu_limit(value, source):
        if value is not None and value > 0:
            cpu_limits.append(value)
            constraints.append({"resource": "cpu", "limit": value, "source": str(source)})

    for kind, directory, controllers in cgroup_directories(cgroup_text, mountinfo_text):
        if kind == "cgroup2":
            quota_path = directory / "cpu.max"
            quota = (read_text(quota_path) or "").split()
            if len(quota) == 2:
                amount = nonnegative_integer(quota[0])
                period = nonnegative_integer(quota[1])
                if amount is not None and period:
                    cpu_limit(amount / period, quota_path)

            cpuset_path = directory / "cpuset.cpus.effective"
            cpu_limit(cpuset_count(read_text(cpuset_path)), cpuset_path)
            limit_path = directory / "memory.max"
            usage_path = directory / "memory.current"
        else:
            if "cpu" in controllers:
                quota_path = directory / "cpu.cfs_quota_us"
                amount = nonnegative_integer(read_text(quota_path))
                period = nonnegative_integer(read_text(directory / "cpu.cfs_period_us"))
                if amount is not None and period:
                    cpu_limit(amount / period, quota_path)

            if "cpuset" in controllers:
                cpuset_path = directory / "cpuset.effective_cpus"
                cpus = read_text(cpuset_path) or read_text(directory / "cpuset.cpus")
                cpu_limit(cpuset_count(cpus), cpuset_path)

            if "memory" not in controllers:
                continue
            limit_path = directory / "memory.limit_in_bytes"
            usage_path = directory / "memory.usage_in_bytes"

        raw_limit = read_text(limit_path)
        limit = nonnegative_integer(raw_limit)
        if raw_limit is not None and raw_limit != "max" and limit is None:
            unknown_remaining_memory = True
            continue

        # The v1 unlimited sentinel is close to INT64_MAX. No real machine
        # has an exabyte of available RAM; ignore these sentinel limits.
        if limit is None or limit >= 2 ** 60:
            continue

        usage = nonnegative_integer(read_text(usage_path))
        if usage is None:
            unknown_remaining_memory = True
            continue

        remaining = max(0, limit - usage)
        memory_limits.append(remaining)
        constraints.append({"resource": "memory", "limit": remaining, "source": str(limit_path)})

    return cpu_limits, memory_limits, constraints, unknown_remaining_memory


def windows_resources():
    class MemoryStatus(ctypes.Structure):
        _fields_ = [
            ("length", ctypes.c_uint32),
            ("load", ctypes.c_uint32),
            ("total_physical", ctypes.c_uint64),
            ("available_physical", ctypes.c_uint64),
            ("total_pagefile", ctypes.c_uint64),
            ("available_pagefile", ctypes.c_uint64),
            ("total_virtual", ctypes.c_uint64),
            ("available_virtual", ctypes.c_uint64),
            ("available_extended_virtual", ctypes.c_uint64),
        ]

    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.GetActiveProcessorCount.argtypes = [ctypes.c_uint16]
    kernel.GetActiveProcessorCount.restype = ctypes.c_uint32
    processors = kernel.GetActiveProcessorCount(0xffff) or os.cpu_count() or 1

    kernel.GetCurrentProcess.restype = ctypes.c_void_p
    kernel.GetProcessAffinityMask.argtypes = [
        ctypes.c_void_p, ctypes.POINTER(ctypes.c_size_t), ctypes.POINTER(ctypes.c_size_t)
    ]
    process_mask = ctypes.c_size_t()
    system_mask = ctypes.c_size_t()
    if kernel.GetProcessAffinityMask(
        kernel.GetCurrentProcess(), ctypes.byref(process_mask), ctypes.byref(system_mask)
    ) and process_mask.value:
        version_query = getattr(sys, "getwindowsversion", None)
        version = version_query() if version_query else None
        cross_group_default = version is not None and (
            version.build >= 22000
            or (version.product_type != 1 and version.build >= 20348)
        )
        # Windows 11/Server 2022 allow all processor groups by default, but
        # this API reports only the primary group's mask. A full group mask
        # must not cap an unrestricted process to 64 processors.
        if not cross_group_default or process_mask.value != system_mask.value:
            processors = min(processors, bin(process_mask.value).count("1"))

    status = MemoryStatus()
    status.length = ctypes.sizeof(status)
    kernel.GlobalMemoryStatusEx.argtypes = [ctypes.POINTER(MemoryStatus)]
    memory = status.available_physical if kernel.GlobalMemoryStatusEx(ctypes.byref(status)) else None
    return processors, memory


def available_memory(system):
    if system == "Linux":
        text = read_text("/proc/meminfo") or ""
        match = re.search(r"^MemAvailable:\s+(\d+)\s+kB$", text, re.MULTILINE)
        return int(match[1]) * 1024 if match else None

    if system == "Darwin":
        text = run_text(["vm_stat"]) or ""
        page_size = re.search(r"page size of (\d+) bytes", text)
        if not page_size:
            return None

        counts = []
        # Purgeable pages overlap these categories, so do not count them twice.
        for label in ["Pages free", "Pages inactive", "Pages speculative"]:
            match = re.search(r"^" + label + r":\s+(\d+)\.", text, re.MULTILINE)
            if match:
                counts.append(int(match[1]))

        total = nonnegative_integer(run_text(["sysctl", "-n", "hw.memsize"]))
        estimate = sum(counts) * int(page_size[1]) if counts else None
        return min(estimate, total) if estimate is not None and total is not None else estimate

    try:
        pages = os.sysconf("SC_AVPHYS_PAGES")
        page_size = os.sysconf("SC_PAGE_SIZE")
        return pages * page_size if pages >= 0 and page_size > 0 else None
    except (ValueError, OSError, AttributeError):
        return None


def choose_jobs(profile, cpu, memory):
    cpu_jobs = max(1, math.floor(cpu * RESOURCE_FRACTION))
    memory_jobs = (
        max(1, math.floor(memory * RESOURCE_FRACTION / MEMORY_PER_WORKER[profile]))
        if memory is not None else 1
    )
    return min(cpu_jobs, memory_jobs)


def probe(profile):
    system = platform.system()
    cpu = os.cpu_count() or 1
    constraints = []

    if system == "Windows":
        try:
            cpu, memory = windows_resources()
        except (AttributeError, OSError):
            memory = None
    else:
        memory = available_memory(system)

    if hasattr(os, "sched_getaffinity"):
        try:
            affinity = len(os.sched_getaffinity(0))
            if affinity:
                cpu = min(cpu, affinity)
                constraints.append({"resource": "cpu", "limit": affinity, "source": "affinity"})
        except OSError:
            pass

    if system == "Linux":
        cpus, memories, detected, unknown = cgroup_limits(
            read_text("/proc/self/cgroup"), read_text("/proc/self/mountinfo")
        )
        cpu = min([cpu] + cpus)
        if memory is not None:
            memory = min([memory] + memories)
        if unknown:
            memory = None
        constraints.extend(detected)

    return {
        "profile": profile,
        "jobs": choose_jobs(profile, cpu, memory),
        "available_cpu": cpu,
        "available_memory_bytes": memory,
        "resource_fraction": RESOURCE_FRACTION,
        "memory_per_worker_bytes": MEMORY_PER_WORKER[profile],
        "constraints": constraints,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile", required=True, choices=MEMORY_PER_WORKER)
    parser.add_argument("--json", action="store_true", help="print resource diagnostics as JSON")
    arguments = parser.parse_args()
    result = probe(arguments.profile)
    print(json.dumps(result, sort_keys=True) if arguments.json else result["jobs"])


if __name__ == "__main__":
    main()
