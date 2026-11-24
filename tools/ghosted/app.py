"""Packaged entry point for GHOSTED."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

BASE = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(BASE, "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

if "--cli" in sys.argv:
    if BASE not in sys.path:
        sys.path.insert(0, BASE)
    import app_cli as _desktop_cli
    raise SystemExit(_desktop_cli.main(sys.argv[sys.argv.index("--cli") + 1:]))

from ghosted.ui import run
import snap_single_instance


if __name__ == "__main__":
    if snap_single_instance.acquire("ghosted", "GHOSTED"):
        raise SystemExit(run())

# ===== SNAPSMACK EOF =====
