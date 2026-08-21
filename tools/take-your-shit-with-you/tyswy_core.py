"""
TAKE YOUR SHIT WITH YOU — pure helpers shared by the tkinter window (main.py)
and the Linux Chrome/Blink window (linux/app.py).

These two functions were tangled behind a top-level `import tkinter` in main.py,
so a box without tkinter (a headless Linux server, the Blink port) could not
import them. They do no UI work — one formats a byte count, one opens a folder
in the OS file manager — so they live here where either front end can use them.

Nothing here imports tkinter. Keep it that way.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import subprocess
import sys


def human_bytes(n):
    """1234567 -> '1.2 MB'. Byte counts stay whole; everything else gets one
    decimal. Copied verbatim from main.py so both windows read the same."""
    n = float(n or 0)
    for unit in ('B', 'KB', 'MB', 'GB', 'TB'):
        if n < 1024 or unit == 'TB':
            return f'{n:,.1f} {unit}' if unit != 'B' else f'{int(n)} B'
        n /= 1024
    return f'{n:.1f} TB'


def open_in_file_manager(path):
    """Open a folder in the OS file manager. Returns True on success. On Linux
    this is xdg-open, which the Blink port also relies on."""
    try:
        if sys.platform.startswith('win'):
            os.startfile(path)                       # noqa: S606 - a folder we made
        elif sys.platform == 'darwin':
            subprocess.Popen(['open', path])
        else:
            subprocess.Popen(['xdg-open', path])
        return True
    except Exception:
        return False


# ===== SNAPSMACK EOF =====
