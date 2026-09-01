"""Application bootstrap for the Qt SNAP SLAPPER."""

import os
import sys

from PySide6.QtCore import QTimer
from PySide6.QtNetwork import QLocalServer, QLocalSocket
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
    # One library owns one thumbnail queue. Accidentally opening a second copy
    # used to double disk/CPU pressure and could make both windows unresponsive.
    instance_name = "SnapSmack.SnapSlapper.Qt"
    probe = QLocalSocket()
    probe.connectToServer(instance_name)
    if probe.waitForConnected(180):
        probe.disconnectFromServer()
        return 0
    QLocalServer.removeServer(instance_name)
    instance_server = QLocalServer(app)
    if not instance_server.listen(instance_name):
        return 0
    if _log is not None:
        _log.info("SNAP SLAPPER Qt UI ready")

    # A file path on the command line opens straight into the editor;
    # otherwise the library browser is the entry point.
    qa_image = os.environ.get("SNAP_SLAPPER_QA_IMAGE", "")
    qa_marker = os.environ.get("SNAP_SLAPPER_QA_MARKER", "")
    target = qa_image or next(
        (c for c in argv[1:] if c and not c.startswith("-")), None)
    if target:
        window = EditorWindow()
        window.open_path(target)
    else:
        window = LibraryWindow()
    window.show()

    # Frozen-build gate: open and render a real photograph before installation.
    # A package that cannot do both never writes the marker and is not promoted.
    if qa_image and qa_marker:
        def verify_packaged_editor():
            try:
                if not isinstance(window, EditorWindow) or not window.doc:
                    return
                rendered = window.doc.render((80, 60))
                if rendered.width <= 0 or rendered.height <= 0:
                    return
                with open(qa_marker, "w", encoding="utf-8") as handle:
                    handle.write("PASS")
            finally:
                app.quit()
        QTimer.singleShot(900, verify_packaged_editor)

    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
