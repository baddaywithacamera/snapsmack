"""Application bootstrap for the Qt editor shell."""

import sys

from PySide6.QtWidgets import QApplication

from . import theme
from .editor_window import EditorWindow


def main(argv=None):
    argv = list(sys.argv if argv is None else argv)
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName("SNAP SLAPPER")
    app.setStyleSheet(theme.stylesheet())

    window = EditorWindow()
    window.show()

    # If a file path was passed on the command line, open it straight away.
    for candidate in argv[1:]:
        if candidate and not candidate.startswith("-"):
            try:
                import editor_engine
                window.doc = editor_engine.EditorDocument(candidate)
                window.doc.on_change = lambda _doc: window._refresh_actions()
                window._sync_controls_from_doc()
                window._render_preview(keep_view=False)
                window._update_title()
            except Exception:  # noqa: BLE001 — a bad path just opens empty
                pass
            break

    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
