"""COLD SNAP Qt — application entry.

Run from source:  python -m coldsnap_qt        (cwd: tools/coldsnap)
             or:  python tools/coldsnap/coldsnap_qt_launcher.py

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys


def instance_key(version: str = "") -> str:
    """Use a version-scoped single-instance key for -test builds so they run beside prod and installed test builds."""
    if not version:
        from _version import BUILD_VERSION
        version = BUILD_VERSION
    ver = str(version).strip()
    return f"cold-snap-{ver}" if ver.endswith("-test") else "cold-snap"


def _set_windows_app_id():
    """Bind the Windows taskbar icon & grouping to COLD SNAP for both prod and -test."""
    if sys.platform != "win32":
        return
    try:
        import ctypes
        ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("SnapSmack.ColdSnap")
    except Exception:  # noqa: BLE001
        pass


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire(instance_key(), "COLD SNAP"):
        return 0

    _set_windows_app_id()

    from PySide6.QtGui import QIcon
    from PySide6.QtWidgets import QApplication

    from . import theme
    from .main_window import MainWindow

    app = QApplication(sys.argv)
    app.setApplicationName("COLD SNAP")
    icon_path = os.path.join(
        getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
        "assets", "coldsnap.ico")
    if os.path.isfile(icon_path):
        app.setWindowIcon(QIcon(icon_path))
    app.setStyleSheet(theme.stylesheet())
    win = MainWindow()
    win.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
