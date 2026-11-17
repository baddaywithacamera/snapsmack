"""Application bootstrap for BOARD LORD."""

import sys

from PySide6.QtWidgets import QApplication

from slapper_qt import theme  # shared Midnight Lime foundation
from .style import extra_stylesheet
from .main_window import BoardLordWindow

try:
    import snap_log
    _log = snap_log.setup("board_lord")
except Exception:  # noqa: BLE001 — logging must never stop the app
    _log = None


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName("BOARD LORD")
    app.setStyleSheet(theme.stylesheet() + extra_stylesheet())
    if _log is not None:
        _log.info("BOARD LORD ready")

    window = BoardLordWindow()
    window.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
