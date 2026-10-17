"""Packaged entry point for LOG FLOGGER."""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import os
import sys

BASE = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(BASE, "..", "_shared")
for _p in (os.path.normpath(SHARED), BASE):
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)

from logflogger.ui import run

if __name__ == "__main__":
    run()

# ===== SNAPSMACK EOF =====
