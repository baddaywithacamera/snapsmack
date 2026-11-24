"""DRIVE BY application entry point."""

import os
import sys


def instance_key(version: str = "") -> str:
    if not version:
        from _version import BUILD_VERSION
        version = BUILD_VERSION
    return "drive-by-test" if str(version).strip().endswith("-test") else "drive-by"


def _set_windows_app_id():
    if sys.platform != "win32":
        return
    try:
        import ctypes
        ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("SnapSmack.DriveBy")
    except Exception:  # noqa: BLE001
        pass


def create_window():
    from .main_window import MainWindow
    return MainWindow()


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire(instance_key(), "DRIVE BY"):
        return 0

    _set_windows_app_id()

    from PySide6.QtGui import QIcon
    from PySide6.QtWidgets import QApplication

    from . import theme

    app = QApplication(sys.argv)
    app.setApplicationName("DRIVE BY")
    icon_path = os.path.join(
        getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
        "assets", "driveby.ico")
    if os.path.isfile(icon_path):
        app.setWindowIcon(QIcon(icon_path))
    app.setStyleSheet(theme.stylesheet())
    win = create_window()
    win.show()
    return app.exec()


# ===== SNAPSMACK EOF =====
