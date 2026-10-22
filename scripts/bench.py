#!/usr/bin/env python3
"""QHturbo benchmark runner.

Two commands:

  php    Compare PHP build variants (baseline vs qhturbo vs qhturbo-pgo) of one
         PHP branch, in interpreter and JIT modes.
  cross  Compare one PHP build against Python, Node.js and Go on the same
         workloads (benchmarks/workloads/<name>/main.{php,py,js,go}).

Every run records wall time and peak RSS of the child process. Runs are
interleaved across contenders (A B C A B C ...) after warm-up so that machine
drift affects all of them equally. Each workload's stdout must be identical
for every contender, otherwise the result is marked INVALID.
"""
import argparse
import datetime
import json
import os
import platform
import random
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LAB = Path(os.environ.get("QHT_LAB", ROOT))
WORKLOADS = ROOT / "benchmarks" / "workloads"

PHP_MODES = {
    # OPcache on, JIT off: measures the Zend VM interpreter.
    "interp": ["-d", "opcache.enable_cli=1", "-d", "opcache.jit=disable"],
    # OPcache + tracing JIT (PHP >= 8.0).
    "jit": ["-d", "opcache.enable_cli=1", "-d", "opcache.jit=tracing",
            "-d", "opcache.jit_buffer_size=128M"],
}


def php_cmd(php_ver, variant, mode):
    base = LAB / "build" / php_ver / variant
    binary = base / "bin" / "php"
    if not binary.exists():
        sys.exit(f"missing {binary}; run QHT_PHP={php_ver} scripts/build.sh {variant}")
    cmd = [str(binary), "-n", "-d", "memory_limit=-1"]
    so = base / "lib" / "opcache.so"
    if so.exists():
        cmd += ["-d", f"zend_extension={so}"]
    return cmd + PHP_MODES[mode]


def run_once_wait4(cmd):
    """Run cmd, return (seconds, peak_rss_mib, stdout) using wait4 for per-process RSS."""
    with tempfile.TemporaryFile() as err:
        start = time.perf_counter()
        proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=err)
        out = proc.stdout.read()
        _, status, usage = os.wait4(proc.pid, 0)
        elapsed = time.perf_counter() - start
        proc.returncode = os.waitstatus_to_exitcode(status)
        if proc.returncode != 0:
            err.seek(0)
            raise RuntimeError(f"{' '.join(cmd)} exited {proc.returncode}: {err.read().decode()[-500:]}")
    return elapsed, usage.ru_maxrss / 1024.0, out.decode()


def bootstrap_ci_ratio(a, b, iters=2000, seed=1):
    """95% bootstrap CI of median(b)/median(a)."""
    rng = random.Random(seed)
    ratios = []
    for _ in range(iters):
        sa = [rng.choice(a) for _ in a]
        sb = [rng.choice(b) for _ in b]
        ratios.append(statistics.median(sb) / statistics.median(sa))
    ratios.sort()
    return ratios[int(0.025 * iters)], ratios[int(0.975 * iters)]


def summarize(times):
    med = statistics.median(times)
    sd = statistics.stdev(times) if len(times) > 1 else 0.0
    return {"median": med, "min": min(times), "max": max(times),
            "stdev": sd, "cv": sd / med if med else 0.0}


def machine_info():
    cpu = "unknown"
    try:
        for line in open("/proc/cpuinfo"):
            if line.startswith("model name"):
                cpu = line.split(":", 1)[1].strip()
                break
    except OSError:
        pass
    gcc = subprocess.run(["gcc", "--version"], capture_output=True, text=True).stdout.splitlines()
    rev = subprocess.run(["git", "-C", str(ROOT), "rev-parse", "--short", "HEAD"],
                         capture_output=True, text=True).stdout.strip() or "uncommitted"
    return {
        "date_utc": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M"),
        "cpu": f"{cpu} ({os.cpu_count()} vCPU)",
        "kernel": platform.release(),
        "os": platform.freedesktop_os_release().get("PRETTY_NAME", "?")
        if hasattr(platform, "freedesktop_os_release") else "?",
        "gcc": gcc[0] if gcc else "?",
        "qhturbo_rev": rev,
    }


def measure(contenders, workloads, size, runs, warmup):
    """contenders: {label: (cmd_prefix, ext)}; returns results dict."""
    spec = json.loads((WORKLOADS.parent / "workloads.json").read_text())
    results = {}
    for wl in workloads:
        args = spec[wl][size]
        cmds = {label: prefix + [str(WORKLOADS / wl / f"main.{ext}")] + args
                for label, (prefix, ext) in contenders.items()}
        outputs = {}
        for label, cmd in cmds.items():
            for _ in range(warmup):
                outputs[label] = run_once_wait4(cmd)[2]
        samples = {label: {"t": [], "rss": []} for label in cmds}
        for _ in range(runs):
            for label, cmd in cmds.items():
                t, rss, out = run_once_wait4(cmd)
                samples[label]["t"].append(t)
                samples[label]["rss"].append(rss)
                outputs.setdefault(label, out)
        ref_out = next(iter(outputs.values()))
        results[wl] = {}
        for label in cmds:
            s = summarize(samples[label]["t"])
            s["rss_mib"] = max(samples[label]["rss"])
            s["valid"] = outputs[label] == ref_out
            s["samples"] = samples[label]["t"]
            results[wl][label] = s
        line = "  ".join(f"{l}={results[wl][l]['median']:.3f}s" for l in cmds)
        print(f"[bench] {wl:14s} {line}", file=sys.stderr)
    return results


def fmt_ratio(base, other, factor=False):
    lo, hi = bootstrap_ci_ratio(base["samples"], other["samples"])
    ratio = other["median"] / base["median"]
    sig = "" if lo <= 1.0 <= hi else (" ✔" if ratio < 1 else " ✘")
    if factor:
        # "2.50× faster" reads better than "+150%" for big gaps.
        return f"{1 / ratio:.2f}×{sig}"
    return f"{(1 / ratio - 1) * 100:+.1f}%{sig}"


def write_report(out_dir, title, meta, results, labels, base_label, factor=False):
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "raw").mkdir(exist_ok=True)
    (out_dir / "raw" / "results.json").write_text(json.dumps({"meta": meta, "results": results}, indent=1))
    lines = [f"# {title}", ""]
    for k, v in meta.items():
        lines.append(f"- **{k}**: {v}")
    speed = "speed factor (>1 = faster)" if factor else "speed-up"
    lines += ["", "Median wall time in seconds (lower is better), peak RSS in MiB. "
              f"{speed[0].upper() + speed[1:]} is relative to `{base_label}`; ✔/✘ = 95% bootstrap CI of the "
              "median ratio excludes 1.0 (faster/slower), no mark = not significant.", ""]
    cols = (["Workload"] + [f"{l} (s)" for l in labels]
            + [f"{l} vs {base_label}" for l in labels if l != base_label]
            + [f"{l} RSS" for l in labels] + ["max CV"])
    lines += ["| " + " | ".join(cols) + " |", "|" + "---|" * len(cols)]
    geo = {l: [] for l in labels}
    for wl, res in results.items():
        cells = []
        for l in labels:
            cells.append(f"{res[l]['median']:.3f}" + ("" if res[l]["valid"] else " INVALID"))
            geo[l].append(res[l]["median"] / res[base_label]["median"])
        cells += [fmt_ratio(res[base_label], res[l], factor) for l in labels if l != base_label]
        cells += [f"{res[l]['rss_mib']:.0f}" for l in labels]
        cells.append(f"{max(res[l]['cv'] for l in labels) * 100:.1f}%")
        lines.append(f"| {wl} | " + " | ".join(cells) + " |")
    def geo_fmt(g):
        m = statistics.geometric_mean(g)
        return f"{1 / m:.2f}×" if factor else f"{(1 / m - 1) * 100:+.1f}%"
    lines += ["", f"**Geometric mean {speed} vs {base_label}:** " + ", ".join(
        f"{l}: {geo_fmt(g)}" for l, g in geo.items() if l != base_label), ""]
    (out_dir / "summary.md").write_text("\n".join(lines))
    print("\n".join(lines))


def cmd_php(a):
    variants = a.variants.split(",")
    modes = a.modes.split(",")
    if a.php.startswith("7.") and "jit" in modes:
        print("[bench] PHP 7.x has no JIT; skipping jit mode", file=sys.stderr)
        modes.remove("jit")
    workloads = a.workloads.split(",") if a.workloads else sorted(
        p.name for p in WORKLOADS.iterdir() if (p / "main.php").exists())
    stamp = datetime.datetime.now().strftime("%Y-%m-%d")
    for mode in modes:
        contenders = {v: (php_cmd(a.php, v, mode), "php") for v in variants}
        meta = machine_info() | {
            "php": a.php, "mode": mode, "runs": a.runs, "warmup": a.warmup, "size": a.size,
            "variants": ", ".join(f"{v} sha256={(LAB / 'build' / a.php / v / 'BUILD-INFO.txt').read_text().split('sha256=')[1][:12]}"
                                  for v in variants),
        }
        res = measure(contenders, workloads, a.size, a.runs, a.warmup)
        out = Path(a.out or ROOT / "results" / f"{stamp}-php{a.php}-{a.name}") / mode
        write_report(out, f"PHP {a.php} variants — {mode}", meta, res, variants, variants[0])


def cmd_cross(a):
    """PHP builds (one or more branches) against other languages, or only
    against each other with --langs php (e.g. --php 7.4,8.5)."""
    workloads = a.workloads.split(",") if a.workloads else sorted(
        p.name for p in WORKLOADS.iterdir() if (p / "main.php").exists())
    versions = a.php.split(",")
    langs = a.langs.split(",")
    tag = "-".join(versions)
    out = Path(a.out or ROOT / "results" / f"{datetime.date.today()}-cross-php{tag}-{'-'.join(langs)}")
    contenders = {}
    for ver in versions:
        for mode in a.modes.split(","):
            if mode == "jit" and ver.startswith("7."):
                continue  # no JIT before PHP 8.0
            contenders[f"php{ver}-{mode}"] = (php_cmd(ver, a.variant, mode), "php")
    if "python" in langs and shutil.which("python3"):
        contenders["python3"] = (["python3"], "py")
    if "node" in langs and shutil.which("node"):
        contenders["node"] = (["node"], "js")
    if "go" in langs and shutil.which("go"):
        # Build each Go workload once (compile time excluded) and run the
        # binary through a shim that picks it from the workload path.
        gobin = out / "raw" / "gobin"
        gobin.mkdir(parents=True, exist_ok=True)
        for wl in workloads:
            subprocess.run(["go", "build", "-o", str(gobin / wl), str(WORKLOADS / wl / "main.go")], check=True)
        shim = gobin / "run.sh"
        shim.write_text('#!/bin/sh\nwl=$(basename "$(dirname "$1")"); shift\nexec "$(dirname "$0")/$wl" "$@"\n')
        shim.chmod(0o755)
        contenders["go"] = ([str(shim)], "go")
    labels = list(contenders)

    def version_of(cmd):
        r = subprocess.run(cmd, capture_output=True, text=True)
        return (r.stdout or r.stderr).strip().splitlines()[0] if r.returncode == 0 else "?"

    meta = machine_info() | {
        "php": ", ".join(f"{v} ({a.variant}: {version_of(php_cmd(v, a.variant, 'interp') + ['-v'])})" for v in versions),
        "runs": a.runs, "warmup": a.warmup, "size": a.size,
    }
    if "python3" in contenders:
        meta["python"] = version_of(["python3", "-V"])
    if "node" in contenders:
        meta["node"] = version_of(["node", "-v"])
    if "go" in contenders:
        meta["go"] = version_of(["go", "version"]) + " (prebuilt binary, compile time excluded)"
    meta["note"] = "Single-threaded CPU workloads; interpreters include start-up time."
    res = measure(contenders, workloads, a.size, a.runs, a.warmup)
    write_report(out, f"Comparison: {', '.join(labels)}", meta, res, labels, labels[0], factor=True)


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    for name in ("php", "cross"):
        s = sub.add_parser(name)
        s.add_argument("--php", default=os.environ.get("QHT_PHP", "8.5"))
        s.add_argument("--runs", type=int, default=7)
        s.add_argument("--warmup", type=int, default=1)
        s.add_argument("--size", choices=["full", "quick"], default="full")
        s.add_argument("--workloads", help="comma-separated subset")
        s.add_argument("--modes", default="interp,jit")
        s.add_argument("--out")
    sub.choices["php"].add_argument("--variants", default="baseline,qhturbo")
    sub.choices["php"].add_argument("--name", default="variants")
    sub.choices["cross"].add_argument("--variant", default="baseline")
    sub.choices["cross"].add_argument("--langs", default="php,python,node,go",
                                      help="which languages to include besides PHP")
    a = p.parse_args()
    cmd_php(a) if a.cmd == "php" else cmd_cross(a)


if __name__ == "__main__":
    main()
