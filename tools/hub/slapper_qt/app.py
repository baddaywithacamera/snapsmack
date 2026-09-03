"""Application bootstrap for the Qt editor shell."""

import os
import sys

from PySide6.QtCore import QTimer
from PySide6.QtWidgets import QApplication, QMessageBox

from . import theme, BUILD_VERSION
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
    # Windows appends the application display name to individual window titles.
    # Keep it to the product name so title bars do not repeat the name or carry
    # a marketing description.
    app.setApplicationDisplayName("SNAP SLAPPER")
    app.setApplicationVersion(BUILD_VERSION)
    app.setStyleSheet(theme.stylesheet())
    if _log is not None:
        _log.info("SNAP SLAPPER Qt UI ready")

        def report_uncaught(exc_type, exc_value, exc_traceback):
            _log.critical("Unhandled SNAP SLAPPER error", exc_info=(
                exc_type, exc_value, exc_traceback))
            QMessageBox.critical(
                None, "SNAP SLAPPER stopped an error",
                f"The operation could not continue. Your original photographs were not changed.\n\n"
                f"{exc_value}\n\nDetails were saved to:\n{_log.log_path}")
        sys.excepthook = report_uncaught

    # A file path on the command line opens straight into the editor;
    # otherwise the library browser is the entry point. The frozen-build gate
    # supplies its real test photo through the environment and must exercise
    # this same editor path, including layered PSD export.
    qa_image = os.environ.get("SNAP_SLAPPER_QA_IMAGE", "")
    qa_marker = os.environ.get("SNAP_SLAPPER_QA_MARKER", "")
    qa_psd = os.environ.get("SNAP_SLAPPER_QA_PSD", "")
    target = qa_image or next(
        (c for c in argv[1:] if c and not c.startswith("-")), None)
    if target:
        window = EditorWindow()
        if os.path.splitext(target)[1].lower() == ".slapper":
            window.open_project_path(target)
        else:
            window.open_path(target)
    else:
        window = LibraryWindow()
    window.show()

    # Packaged-build smoke test: open a real image, prove the Qt event loop can
    # start, write a marker, and exit without requiring desktop interaction.
    if qa_image and qa_marker:
        def finish_qa():
            try:
                ready = os.path.isfile(qa_image)
                if ready and qa_psd and isinstance(window, EditorWindow) and window.doc:
                    from .psd_export import export_layered_psd
                    export_layered_psd(window.doc, qa_psd)
                    ready = os.path.isfile(qa_psd)
                if ready:
                    with open(qa_marker, "w", encoding="utf-8") as marker:
                        marker.write("ok\n")
            finally:
                app.quit()
        QTimer.singleShot(750, finish_qa)

    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
