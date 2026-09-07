"""COLD SNAP Qt — application entry.

Run from source:  python -m coldsnap_qt        (cwd: tools/coldsnap)
             or:  python tools/coldsnap/coldsnap_qt_launcher.py

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import sys


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire("cold-snap", "COLD SNAP"):
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
