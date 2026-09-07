"""Process-lifetime single-instance guard shared by SnapSmack desktop tools.

The operating system owns the lock, so a crash or Task Manager exit releases it
automatically.  No stale PID file can strand a tool or falsely report it running.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import os
import re
import sys
import tempfile

_GUARDS = []


def acquire(app_id: str, display_name: str) -> bool:
    """Return True for the first process and False for every later process."""
    safe_id = re.sub(r"[^A-Za-z0-9_.-]+", "-", app_id).strip("-") or "desktop"
    if sys.platform == "win32":
        import ctypes

        kernel32 = ctypes.windll.kernel32
        kernel32.SetLastError(0)
        handle = kernel32.CreateMutexW(None, False, f"Local\\SnapSmack.{safe_id}")
        if not handle:
            return True  # A lock failure must not make the application unusable.
        if kernel32.GetLastError() == 183:  # ERROR_ALREADY_EXISTS
            kernel32.CloseHandle(handle)
            ctypes.windll.user32.MessageBoxW(
                None,
                f"{display_name} is already running. Check the taskbar or system tray.",
                "Already running",
                0x00000040,
            )
            return False
        _GUARDS.append(handle)
        return True

    import fcntl

    path = os.path.join(tempfile.gettempdir(), f"snapsmack-{safe_id}.lock")
    handle = open(path, "a+", encoding="utf-8")
    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        handle.close()
        print(f"{display_name} is already running.", file=sys.stderr)
        return False
    _GUARDS.append(handle)
    return True


# ===== SNAPSMACK EOF =====
