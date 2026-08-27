"""Launch the SNAP SLAPPER Qt editor (Phase 1).

Run from anywhere:  python tools/hub/run_slapper_qt.py  [optional image path]

This adds tools/hub to the import path so the Qt shell can reuse the existing
``editor_engine`` unchanged, then starts the app.
"""

import os
import sys

HUB_DIR = os.path.dirname(os.path.abspath(__file__))
SHARED_DIR = os.path.join(os.path.dirname(HUB_DIR), "_shared")
for _path in (HUB_DIR, SHARED_DIR):
    if os.path.isdir(_path) and _path not in sys.path:
        sys.path.insert(0, _path)

from slapper_qt.app import main  # noqa: E402 — path must be set first

if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
