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

BUILD_VERSION = "0.7.29"

# WHAT IS ACTUALLY ON THE OWNER'S MACHINE. A floor, not a target.
#
# This file said 0.7.26 while his screenshots show COLD SNAP 0.7.28 running.
# build.bat runs bump_version.py and then reads this file, so the next build
# would have produced 0.7.27 -- A NUMBER GOING BACKWARDS, shipped over a newer
# install. Codex caught it in build preflight before it happened.
#
# How it drifted: bump_version.py writes the new number into this file, so every
# build leaves a change here that has to be COMMITTED. Twice it was not. The
# committed history runs 0.7.21, .22, .23, .25, .26 and stops on 6 October, so
# .24, .27 and .28 were built on his machine and their bumps never landed. The
# gap at .24 is the same fault a month earlier that nobody noticed.
#
# bump_version.py now refuses to produce a version at or below this floor, so a
# stale checkout fails loudly instead of quietly rolling him back. Raise it when
# a build is confirmed installed.
INSTALLED_VERSION = "0.7.28"
# ===== SNAPSMACK EOF =====
