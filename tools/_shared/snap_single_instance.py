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


def _tell_user(display_name: str) -> None:
    """Raise an existing app window when possible, otherwise explain the no-op."""
    try:
        import ctypes
        from ctypes import wintypes

        user32 = ctypes.windll.user32
        found = []

        @ctypes.WINFUNCTYPE(wintypes.BOOL, wintypes.HWND, wintypes.LPARAM)
        def visit(hwnd, _):
            if not user32.IsWindowVisible(hwnd):
                return True
            length = user32.GetWindowTextLengthW(hwnd)
            if not length:
                return True
            title = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, title, length + 1)
            if display_name.casefold() in title.value.casefold():
                found.append(hwnd)
                return False
            return True

        user32.EnumWindows(visit, 0)
        if found:
            user32.ShowWindow(found[0], 9)  # SW_RESTORE
            user32.SetForegroundWindow(found[0])
            return
        user32.MessageBoxW(
            None,
            f"{display_name} is already running. Check the taskbar or system tray.",
            "Already running",
            0x00000040,
        )
    except Exception:  # noqa: BLE001 — duplicate still exits if UI activation fails
        pass


def acquire(app_id: str, display_name: str) -> bool:
    """Return True for the first process and False for every later process."""
    safe_id = re.sub(r"[^A-Za-z0-9_.-]+", "-", app_id).strip("-") or "desktop"
    if sys.platform == "win32":
        import ctypes

        kernel32 = ctypes.windll.kernel32
        created = []
        # Local catches every normal second launch in this sign-in session.
        # Global also catches accidental launches in another Windows session
        # (Fast User Switching / elevated launch). It can be denied by policy,
        # so Local remains the mandatory guard and Global is best effort.
        for scope in ("Local", "Global"):
            kernel32.SetLastError(0)
            handle = kernel32.CreateMutexW(
                None, False, f"{scope}\\SnapSmack.{safe_id}")
            if not handle:
                continue
            if kernel32.GetLastError() == 183:  # ERROR_ALREADY_EXISTS
                kernel32.CloseHandle(handle)
                for owned in created:
                    kernel32.CloseHandle(owned)
                _tell_user(display_name)
                return False
            created.append(handle)
        if not created:
            return True  # Lock infrastructure failure must not strand the app.
        _GUARDS.extend(created)
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
