"""COLD SNAP Qt — application entry.

Run from source:  python -m coldsnap_qt        (cwd: tools/coldsnap)
             or:  python tools/coldsnap/coldsnap_qt_launcher.py

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import sys


def instance_key(version: str = "") -> str:
    """Use a separate single-instance key for -test builds so they run beside prod."""
    if not version:
        from _version import BUILD_VERSION
        version = BUILD_VERSION
    return "cold-snap-test" if str(version).strip().endswith("-test") else "cold-snap"


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire(instance_key(), "COLD SNAP"):
        return 0

    from PySide6.QtWidgets import QApplication

    from . import theme
    from .main_window import MainWindow

    app = QApplication(sys.argv)
    app.setApplicationName("COLD SNAP")
    app.setStyleSheet(theme.stylesheet())
    win = MainWindow()
    win.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
