#!/usr/bin/env python3
"""Recommend PHP-FPM process-manager settings from RAM and worker memory.

  fpm-tune.py                         measure running php-fpm workers, use host RAM
  fpm-tune.py --worker-rss 60         assume 60 MiB per worker
  fpm-tune.py --reserve 2048          keep 2 GiB for OS, MySQL, Redis, nginx
  fpm-tune.py --ram 8192 --worker-rss 45 --pools 3

max_children is bounded by memory: (RAM - reserve) / pools / worker RSS (p95).
Too many children cause swapping and make every request slower, so the
calculation is deliberately conservative.
"""
import argparse
import os
import statistics
import subprocess


def host_ram_mib():
    with open("/proc/meminfo") as f:
        for line in f:
            if line.startswith("MemTotal:"):
                return int(line.split()[1]) // 1024
    raise SystemExit("cannot read /proc/meminfo; pass --ram")


def measured_worker_rss(process_name):
    """RSS (MiB) of running FPM pool workers, excluding the master."""
    out = subprocess.run(["ps", "-eo", "rss=,args="], capture_output=True, text=True).stdout
    rss = []
    for line in out.splitlines():
        size, _, args = line.strip().partition(" ")
        if process_name in args and "pool" in args:
            rss.append(int(size) / 1024)
    return rss


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--ram", type=int, help="total RAM in MiB (default: this host)")
    p.add_argument("--reserve", type=int, help="MiB kept for OS/DB/cache (default: 25%% of RAM, min 1024)")
    p.add_argument("--worker-rss", type=float, help="MiB per PHP worker (default: measure running php-fpm)")
    p.add_argument("--pools", type=int, default=1, help="number of FPM pools sharing the RAM")
    p.add_argument("--cpus", type=int, default=os.cpu_count())
    p.add_argument("--process-name", default="php-fpm")
    a = p.parse_args()

    ram = a.ram or host_ram_mib()
    reserve = a.reserve if a.reserve is not None else max(1024, ram // 4)
    source = "given"
    rss = a.worker_rss
    if rss is None:
        samples = measured_worker_rss(a.process_name)
        if samples:
            samples.sort()
            rss = samples[int(0.95 * (len(samples) - 1))]
            source = f"p95 of {len(samples)} running workers (median {statistics.median(samples):.0f} MiB)"
        else:
            rss = 64.0
            source = "default guess - measure under real traffic for an accurate value"
    budget = max(0, ram - reserve) // a.pools
    max_children = max(2, int(budget // rss))
    # Spare servers scale with CPUs; bounded by max_children.
    start = min(max_children, max(2, a.cpus))
    min_spare = min(max_children, max(1, a.cpus // 2))
    max_spare = min(max_children, max(min_spare + 1, a.cpus * 2))

    print(f"RAM {ram} MiB, reserve {reserve} MiB, {a.pools} pool(s) -> {budget} MiB per pool")
    print(f"worker RSS {rss:.0f} MiB ({source})")
    print()
    print("; paste into the pool config (e.g. /opt/qhturbo/8.5/etc/php-fpm.d/www.conf)")
    if max_children <= a.cpus * 2:
        print("; little RAM: a static pool avoids fork overhead")
        print("pm = static")
        print(f"pm.max_children = {max_children}")
    else:
        print("pm = dynamic")
        print(f"pm.max_children = {max_children}")
        print(f"pm.start_servers = {start}")
        print(f"pm.min_spare_servers = {min_spare}")
        print(f"pm.max_spare_servers = {max_spare}")
    print("pm.max_requests = 1000")
    if max_children > a.cpus * 8:
        print()
        print(f"; note: {max_children} children on {a.cpus} CPUs only helps if requests mostly wait on I/O"
              " (DB, HTTP APIs). For that pattern QHturbo Performance mode with --concurrency > 1"
              " serves far more requests per MiB.")


if __name__ == "__main__":
    main()
