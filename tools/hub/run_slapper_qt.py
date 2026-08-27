"""Launch the SNAP SLAPPER Qt editor (Phase 1).

Run from anywhere:  python tools/hub/run_slapper_qt.py  [optional image path]

This adds tools/hub to the import path so the Qt shell can reuse the existing
``editor_engine`` unchanged, then starts the app.
"""

import os
import sys

HUB_DIR = os.path.dirname(os.path.abspath(__file__))
if HUB_DIR not in sys.path:
    sys.path.insert(0, HUB_DIR)

from slapper_qt.app import main  # noqa: E402 — path must be set first

if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
