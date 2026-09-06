"""COLD SNAP — PySide6 (Qt) shell. MIDNIGHT LIME, same as SNAP SLAPPER.

The SLAPPER playbook (Sean, 2026-09-06: "rebuild as QT"): this package replaces
ONLY the window — every engine underneath is the existing, tested code, reused
untouched:

    sumna_offline.py   drafts, batches (sessions), thumbs, trigram slicing,
                       export/import, the store-and-forward SyncEngine
    sumna_post.py      the network posters + positive verification
    config.py          connection settings (shared-creds aware)
    profile_manager.py saved site profiles

The Tk app (coldsnap.py) stays intact and runnable; the exe only switches to
this shell after Sean live-tests it (feedback_test_as_users_no_fast_fixes).

Plain-words UI contract (the reasons this rebuild exists):
  * "QUEUE POST" — not "✓ OFFLINE POST" — a button says what it does.
  * No "No session — create one above" dead ends: a batch is auto-created.
  * Connection = pick a saved site. The raw fields live behind one expander.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

BUILD_VERSION_QT = "0.8.0-qt"

# The engine modules are siblings in tools/coldsnap; _shared is one up. Make
# both importable no matter how this package is launched (source, -m, frozen).
_PKG_DIR = os.path.dirname(os.path.abspath(__file__))
_COLDSNAP_DIR = os.path.dirname(_PKG_DIR)
_SHARED_DIR = os.path.join(os.path.dirname(_COLDSNAP_DIR), "_shared")
for _p in (_COLDSNAP_DIR, _SHARED_DIR):
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)

# ===== SNAPSMACK EOF =====
