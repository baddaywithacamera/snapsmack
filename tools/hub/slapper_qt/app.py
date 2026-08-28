"""Application bootstrap for the Qt editor shell."""

import sys

from PySide6.QtWidgets import QApplication

from . import theme
from .editor_window import EditorWindow
from .library_window import LibraryWindow

try:
    import snap_log
    _log = snap_log.setup("snap_slapper")
except Exception:  # noqa: BLE001 — never let logging setup stop the app
    _log = None


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName("SNAP SLAPPER")
    app.setStyleSheet(theme.stylesheet())
    if _log is not None:
        _log.info("SNAP SLAPPER Qt UI ready")

    # A file path on the command line opens straight into the editor;
    # otherwise the library browser is the entry point.
    target = next((c for c in argv[1:] if c and not c.startswith("-")), None)
    if target:
        window = EditorWindow()
        window.open_path(target)
    else:
        window = LibraryWindow()
    window.show()

    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
