"""COLD SNAP (Qt shell) — source/pyinstaller launcher.

Same debug-log capture as the Tk shell (coldsnap.py) so a windowed exe never
loses a crash: stdout/stderr go to the shared logs dir before anything heavy
imports. The Tk shell stays untouched and runnable; the exe target switches to
this file only after Sean live-tests the Qt shell.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

_BASE = os.path.dirname(sys.executable) if getattr(sys, "frozen", False) \
    else os.path.dirname(os.path.abspath(__file__))
if _BASE not in sys.path:
    sys.path.insert(0, _BASE)

from _version import BUILD_VERSION  # noqa: E402


def _setup_log() -> str:
    log_path = os.path.join(_BASE, "coldsnap-qt-debug.log")
    try:
        _sd = os.path.join(_BASE, "..", "_shared")
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_home
        log_path = snap_home.log_path("coldsnap", "qt-debug")
    except Exception:  # noqa: BLE001 — shared home unreachable, log next to exe
        pass
    try:
        lf = open(log_path, "a", encoding="utf-8", buffering=1)
        import datetime
        lf.write(f"\n{'=' * 60}\n  COLD SNAP (Qt) {BUILD_VERSION}  —  "
                 f"{datetime.datetime.now():%Y-%m-%d %H:%M:%S}\n{'=' * 60}\n")
        sys.stdout = lf
        sys.stderr = lf
    except Exception:  # noqa: BLE001 — no log is never a reason not to launch
        pass
    return log_path


LOG_PATH = _setup_log()

from coldsnap_qt.app import main  # noqa: E402

raise SystemExit(main())

# ===== SNAPSMACK EOF =====
