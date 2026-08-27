"""Application bootstrap for the Qt editor shell."""

import sys

from PySide6.QtWidgets import QApplication

from . import theme
from .editor_window import EditorWindow
from .library_window import LibraryWindow


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName("SNAP SLAPPER")
    app.setStyleSheet(theme.stylesheet())

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
