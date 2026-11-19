"""INSTANT REPLAY application entry point."""

import os
import sys


def instance_key(version: str = "") -> str:
    if not version:
        from _version import BUILD_VERSION
        version = BUILD_VERSION
    return "instant-replay-test" if str(version).strip().endswith("-test") else "instant-replay"


def _set_windows_app_id():
    if sys.platform != "win32":
        return
    try:
        import ctypes
        ctypes.windll.shell32.SetCurrentProcessExplicitAppUserModelID("SnapSmack.InstantReplay")
    except Exception:  # noqa: BLE001
        pass


def main() -> int:
    import snap_single_instance
    if not snap_single_instance.acquire(instance_key(), "INSTANT REPLAY"):
        return 0

    _set_windows_app_id()

    from PySide6.QtGui import QIcon
    from PySide6.QtWidgets import QApplication

    from . import theme
    from .main_window import MainWindow

    app = QApplication(sys.argv)
    app.setApplicationName("INSTANT REPLAY")
    icon_path = os.path.join(
        getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
        "assets", "instantreplay.ico")
    if os.path.isfile(icon_path):
        app.setWindowIcon(QIcon(icon_path))
    app.setStyleSheet(theme.stylesheet())
    win = MainWindow()
    win.show()
    return app.exec()


# ===== SNAPSMACK EOF =====
