"""TICKET WICKET's forum client — now shared.

The implementation moved to tools/_shared/snap_forum.py when FORUM CLI needed
the same calls. Two copies of an HTTP client drift apart, and the copy that
drifts is the one nobody is reading. This re-export keeps every existing
import working: `from ticketwicket import forum as forummod` still gives you
Forum, ForumError, AFFECTED and DISCLOSURE_FLOOR.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

_SHARED = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "_shared"))
if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

from snap_forum import (  # noqa: F401
    AFFECTED,
    DISCLOSURE_FLOOR,
    Forum,
    ForumError,
)

__all__ = ["Forum", "ForumError", "AFFECTED", "DISCLOSURE_FLOOR"]
# ===== SNAPSMACK EOF =====
