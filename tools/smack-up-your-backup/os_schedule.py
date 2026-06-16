"""
Smack Up Your Backup — os_schedule.py

Registers ONE OS-level scheduled task that runs a headless "back up all blogs"
on a daily timer, so automation does NOT depend on the GUI being open.

  Windows → Task Scheduler via schtasks (current user, no elevation needed).
  Linux   → a single user-crontab line tagged with a marker.
  macOS   → same crontab path (SUYB runs from source on Mac).

One global switch, whole roster — this replaces nine per-profile schedules.
The command it schedules is `<suyb> --backup-all --silent`, handled by
main.py's __main__ dispatch → headless.run_backup_all().
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import subprocess
from typing import Tuple

TASK_NAME   = "SmackUpYourBackup-AutoBackup"
CRON_MARKER = "# SUYB-AUTO-BACKUP"


def _is_frozen() -> bool:
    return bool(getattr(sys, "frozen", False))


def _invocation() -> str:
    """Quoted command string that runs a headless backup-all and exits."""
    if _is_frozen():
        return f'"{sys.executable}" --backup-all --silent'
    script = os.path.join(os.path.dirname(os.path.abspath(__file__)), "main.py")
    py = sys.executable
    if sys.platform == "win32":
        # prefer pythonw.exe so no console window flashes on each run
        cand = os.path.join(os.path.dirname(py), "pythonw.exe")
        if os.path.exists(cand):
            py = cand
    return f'"{py}" "{script}" --backup-all --silent'


def _valid_time(time_str: str) -> bool:
    try:
        hh, mm = time_str.split(":")
        return 0 <= int(hh) <= 23 and 0 <= int(mm) <= 59
    except Exception:
        return False


# ── Windows (Task Scheduler) ──────────────────────────────────────────────
def _win_set(enabled: bool, time_str: str) -> Tuple[bool, str]:
    if enabled:
        cmd = ["schtasks", "/Create", "/TN", TASK_NAME,
               "/TR", _invocation(), "/SC", "DAILY", "/ST", time_str, "/F"]
    else:
        cmd = ["schtasks", "/Delete", "/TN", TASK_NAME, "/F"]
    try:
        r = subprocess.run(cmd, capture_output=True, text=True)
    except FileNotFoundError:
        return (True, "Schedule removed") if not enabled else (False, "schtasks not found")
    if r.returncode == 0:
        return True, (f"Scheduled daily at {time_str}." if enabled else "Schedule removed.")
    if not enabled:
        return True, "Schedule removed."   # deleting a missing task is fine
    return False, (r.stderr or r.stdout or "schtasks failed").strip()


def _win_state() -> dict:
    try:
        r = subprocess.run(["schtasks", "/Query", "/TN", TASK_NAME],
                           capture_output=True, text=True)
        return {"enabled": r.returncode == 0}
    except FileNotFoundError:
        return {"enabled": False}


# ── Linux / macOS (crontab) ───────────────────────────────────────────────
def _read_crontab() -> str:
    try:
        r = subprocess.run(["crontab", "-l"], capture_output=True, text=True)
        return r.stdout if r.returncode == 0 else ""
    except FileNotFoundError:
        return ""


def _write_crontab(text: str) -> Tuple[bool, str]:
    try:
        p = subprocess.run(["crontab", "-"], input=text, text=True,
                           capture_output=True)
        return (p.returncode == 0, (p.stderr or "").strip())
    except FileNotFoundError:
        return False, "crontab not found"


def _nix_set(enabled: bool, time_str: str) -> Tuple[bool, str]:
    hh, mm = time_str.split(":")
    lines = [ln for ln in _read_crontab().splitlines() if CRON_MARKER not in ln]
    if enabled:
        lines.append(f"{int(mm)} {int(hh)} * * * {_invocation()}  {CRON_MARKER}")
    new = ("\n".join(lines).strip() + "\n") if lines else ""
    ok, err = _write_crontab(new)
    if ok:
        return True, (f"Scheduled daily at {time_str}." if enabled else "Schedule removed.")
    return False, err or "crontab failed"


def _nix_state() -> dict:
    return {"enabled": any(CRON_MARKER in ln for ln in _read_crontab().splitlines())}


# ── Public API ────────────────────────────────────────────────────────────
def set_global_schedule(enabled: bool, time_str: str = "02:00") -> Tuple[bool, str]:
    """Create (enabled) or remove (disabled) the daily backup-all task.
    Returns (success, human-readable message)."""
    if enabled and not _valid_time(time_str):
        return False, "Enter a valid time as HH:MM (24-hour), e.g. 02:00."
    if sys.platform == "win32":
        return _win_set(enabled, time_str)
    return _nix_set(enabled, time_str)


def schedule_state() -> dict:
    """Return {'enabled': bool} reflecting the actual OS task/cron line."""
    if sys.platform == "win32":
        return _win_state()
    return _nix_state()

# ===== SNAPSMACK EOF =====
