"""Qt entry point for UNZUCKER — what the exe runs (unzucker.spec). (SS-1191)

Deliberately tiny: single-instance guard, then the Qt window. main.py is the
old tkinter window and is NOT the entry any more; a build that starts main.py
ships the wrong app. That is not hypothetical -- SYBU 0.7.67 shipped exactly
that way -- so tests/test_qt_shell.py pins this file as the spec's entry script
and fails if it changes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import sys


def main():
    try:
        import snap_single_instance
        if not snap_single_instance.acquire("unzucker", "UNZUCKER"):
            return 0
    except Exception:
        # A missing or failing guard must not stop the tool opening; the worst
        # case is two windows, which is what the Tk build always allowed.
        pass
    from unzucker_qt import run
    return run()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
