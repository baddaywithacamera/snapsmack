"""COLD SNAP Qt — application entry.

Run from source:  python -m coldsnap_qt        (cwd: tools/coldsnap)
             or:  python tools/coldsnap/coldsnap_qt_launcher.py

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import ctypes
import os
import sys


def _icon_path() -> str:
    root = getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    return os.path.join(root, "assets", "coldsnap.ico")


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire("cold-snap", "COLD SNAP"):
        return 0

    # The executable icon alone is not enough for a Qt taskbar button.  Give
    # Windows a stable identity before QApplication creates any windows.
    if sys.platform == "win32":
        try:
            ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID(
                "SnapSmack.ColdSnap")
        except (AttributeError, OSError):
            pass

    from PySide6.QtGui import QIcon
    from PySide6.QtWidgets import QApplication

    from . import theme
    from .main_window import MainWindow

    app = QApplication(sys.argv)
    app.setApplicationName("COLD SNAP")
    icon_path = _icon_path()
    if os.path.isfile(icon_path):
        app.setWindowIcon(QIcon(icon_path))
    app.setStyleSheet(theme.stylesheet())
    win = MainWindow()
    win.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
