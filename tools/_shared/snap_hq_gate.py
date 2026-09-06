"""Official desktop-companion dependency gate.

Packaged SnapSmack companions require SNAP HQ to be installed. Source/dev runs
remain available to contributors and automated tests. This is a product-family
gate, not the separate CMS entitlement decision used by SNAP SLAPPER/LEWK AGAIN.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

from __future__ import annotations

import os
import shutil
import sys


def installed() -> bool:
    override = os.environ.get("SNAPSMACK_HQ_EXECUTABLE", "").strip()
    if override:
        return os.path.isfile(override)
    if os.name == "nt":
        return any(os.path.isfile(path) for path in (
            r"C:\snapsmack\hub\SNAP HQ.exe", r"C:\snapsmack\hub\hub.exe"))
    return bool(shutil.which("snap-hq") or os.path.isfile("/opt/snapsmack/hub/snap-hq"))


def require(*, packaged_only: bool = True) -> None:
    if os.environ.get("SNAPSMACK_DEV_BYPASS_HQ") == "1":
        return
    if packaged_only and not getattr(sys, "frozen", False):
        return
    if not installed():
        raise SystemExit("SNAP HQ is required. Install SNAP HQ, then launch this app from it.")

# ===== SNAPSMACK EOF =====
