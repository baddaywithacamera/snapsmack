# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""
COLD SNAP — single source of truth for the build version.

Both the app window (coldsnap.py) and the draft/export stamps (sumna_offline.py)
import BUILD_VERSION from HERE, so they can never disagree again. build.bat reads
it and bump_version.py increments it. No imports — safe to load from anywhere,
frozen or not, with no risk of an import cycle.
"""

BUILD_VERSION = "0.7.8"
# ===== SNAPSMACK EOF =====
