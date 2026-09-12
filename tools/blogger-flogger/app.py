"""Packaged entry point for BLOGGER FLOGGER."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

BASE = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(BASE, "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

from blogger_flogger.ui import run
import snap_single_instance


if __name__ == "__main__":
    if snap_single_instance.acquire("blogger-flogger", "BLOGGER FLOGGER"):
        raise SystemExit(run())

# ===== SNAPSMACK EOF =====
