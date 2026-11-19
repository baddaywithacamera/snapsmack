"""Detect running/tray-minimized SNAP HQ.exe and offer to quit it before install."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import argparse
import csv
import io
import subprocess
import sys
import time


HQ_EXE_NAME = "SNAP HQ.exe"


def find_hq_pids(*, runner=None) -> list[int]:
    """Return PIDs of running SNAP HQ.exe processes on Windows."""
    run = runner or subprocess.run
    try:
        proc = run(
            ["tasklist", "/FI", f"IMAGENAME eq {HQ_EXE_NAME}", "/FO", "CSV", "/NH"],
            capture_output=True, text=True, timeout=10,
        )
    except Exception:
        return []
    pids: list[int] = []
    for row in csv.reader(io.StringIO(proc.stdout or "")):
        if len(row) >= 2 and row[0].strip().lower() == HQ_EXE_NAME.lower():
            try:
                pids.append(int(row[1].strip()))
            except ValueError:
                pass
    return pids


def quit_hq(*, runner=None, wait_fn=None, timeout: float = 5.0) -> bool:
    """Attempt graceful close of SNAP HQ.exe, falling back to /F if needed."""
    run = runner or subprocess.run
    sleep = wait_fn or time.sleep
    pids = find_hq_pids(runner=run)
    if not pids:
        return True
    run(["taskkill", "/IM", HQ_EXE_NAME], capture_output=True, text=True, timeout=10)
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if not find_hq_pids(runner=run):
            return True
        sleep(0.25)
    run(["taskkill", "/F", "/IM", HQ_EXE_NAME], capture_output=True, text=True, timeout=10)
    return not find_hq_pids(runner=run)


def ensure_hq_closed(*, auto_yes: bool = False, prompt_fn=None, runner=None) -> bool:
    """Detect SNAP HQ.exe (including tray-minimized) and offer to quit it."""
    pids = find_hq_pids(runner=runner)
    if not pids:
        return True
    if not auto_yes:
        ask = prompt_fn or input
        ans = ask(
            f"{HQ_EXE_NAME} is currently running (possibly minimized to the system tray, "
            f"PIDs: {', '.join(map(str, pids))}). Quit {HQ_EXE_NAME} now? [Y/n]: "
        )
        if ans.strip().lower() in {"n", "no"}:
            return False
    return quit_hq(runner=runner)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Check for running SNAP HQ.exe before install.")
    ap.add_argument("--prompt", action="store_true", help="Prompt to quit SNAP HQ.exe if running.")
    ap.add_argument("--yes", action="store_true", help="Quit SNAP HQ.exe without prompting.")
    args = ap.parse_args(argv)
    ok = ensure_hq_closed(auto_yes=args.yes or not args.prompt)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
