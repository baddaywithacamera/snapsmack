"""Packaged entry point for FED UP."""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import os
import sys
BASE = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.join(BASE, "_shared")
# RESTORE rides UNZUCKER's poster; the B2 copy rides SUYB's cloud_client. From
# source they sit in sibling tool folders; in the exe they are bundled flat.
for _p in (SHARED, os.path.join(BASE, "..", "unzucker"), os.path.join(BASE, "..", "smack-up-your-backup"), BASE):
    _p = os.path.normpath(_p)
    if os.path.isdir(_p) and _p not in sys.path:
        sys.path.insert(0, _p)
from fed_up.ui import run
import snap_single_instance
if __name__ == "__main__":
    if snap_single_instance.acquire("fed-up", "FED UP"):
        raise SystemExit(run())
# ===== SNAPSMACK EOF =====
