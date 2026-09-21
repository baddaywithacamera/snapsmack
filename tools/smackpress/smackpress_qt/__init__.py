"""SMACKPRESS Qt — a one-off migration shell on COLD SNAP's engine.

Sean, 2026-09-21: SMACKPRESS stays its own tool (a migration you do once does
not live inside the tool you write in every day), goes Qt like everything
else, and stops owning an editor. It borrows COLD SNAP's: the same COLD TAKE
editor (BIGGIE/TWIGGY, shortcode bar, MOSAIC, picture bucket), the same
SmacktalkPoster, the same batch rail, the same site connection panel, the same
shared profiles and vault. SMACKPRESS adds exactly one thing: the WordPress
side — list the old posts, pull one across (every picture downloaded and
made yours, see smackpress/wp_source.py), hand it to the editor, and remember
what became what.

Layout:  [ COLD SNAP ConnectPanel — the SnapSmack site you are migrating INTO ]
         [ WORDPRESS source pane | COLD TAKE editor + BATCH rail + SEND        ]

Run from source:  python -m smackpress_qt        (cwd: tools/smackpress)
             or:  python tools/smackpress/smackpress_qt_launcher.py
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys

# COLD SNAP's package and the shared modules, by path — same repo, same build.
_HERE = os.path.dirname(os.path.abspath(__file__))
_TOOLS = os.path.dirname(os.path.dirname(_HERE))
for _p in (os.path.join(_TOOLS, "coldsnap"), os.path.join(_TOOLS, "_shared"),
           os.path.dirname(_HERE)):
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)

SHELL_LABEL = "Qt"

# ===== SNAPSMACK EOF =====
