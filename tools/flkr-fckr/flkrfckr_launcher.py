"""Qt entry point for FLKR FCKR — what the exe runs (flkrfckr.spec).

Deliberately tiny: single-instance guard, then the Qt window. main.py is the
old tkinter window and is NOT the entry any more; a build that starts main.py
ships the wrong app (the SYBU 0.7.67 mistake) — tests/test_qt_shell.py pins
the entry script for that reason.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import sys


def main():
    try:
        import snap_single_instance
        if not snap_single_instance.acquire("flkr-fckr", "FLKR FCKR"):
            return 0
    except Exception:
        pass
    from flkrfckr_qt import run
    return run()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
