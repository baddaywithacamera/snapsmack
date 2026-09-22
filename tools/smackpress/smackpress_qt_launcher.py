"""SMACKPRESS Qt launcher — the frozen exe's entry script (copied from coldsnap_qt_launcher.py)."""
# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
import os
import sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from smackpress_qt.app import main
raise SystemExit(main())
# ===== SNAPSMACK EOF =====
