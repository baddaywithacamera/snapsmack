"""SMACKPRESS Qt — application entry (copied from COLD SNAP's app.py)."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import sys


def main() -> int:
    import smackpress_qt  # noqa: F401 — puts coldsnap + _shared on sys.path
    import snap_single_instance
    if not snap_single_instance.acquire("smackpress", "SMACKPRESS"):
        return 0
    from PySide6.QtWidgets import QApplication
    from coldsnap_qt import theme
    from .main_window import MainWindow
    app = QApplication(sys.argv)
    app.setApplicationName("SMACKPRESS")
    app.setStyleSheet(theme.stylesheet())
    win = MainWindow()
    win.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
