"""Launch BOARD LORD — the SnapSmack forum operator console.

Run from anywhere:  python tools/hub/run_board_lord.py

Adds tools/hub to the import path so BOARD LORD can reuse the SNAP SLAPPER Qt
foundation (slapper_qt.theme) unchanged, then starts the app.
"""

import os
import sys

HUB_DIR = os.path.dirname(os.path.abspath(__file__))
SHARED_DIR = os.path.join(os.path.dirname(HUB_DIR), "_shared")
for _path in (HUB_DIR, SHARED_DIR):
    if os.path.isdir(_path) and _path not in sys.path:
        sys.path.insert(0, _path)

from board_lord.app import main  # noqa: E402 — path must be set first

if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
