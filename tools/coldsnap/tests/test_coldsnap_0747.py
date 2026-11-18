"""Tests for COLD SNAP 0.7.47-test: end-to-end site bg and all-block delete/undo."""

import os
import sys

from PIL import Image
from PySide6.QtCore import QEvent, QPointF, Qt
from PySide6.QtGui import QKeyEvent, QMouseEvent
from PySide6.QtWidgets import QApplication, QWidget

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import snap_library
from coldsnap_qt.canvas import BiggieCanvas
from coldsnap_qt.connect_panel import ConnectPanel
from coldsnap_qt.main_window import MainWindow
from sumna_post import SumnaConnection


def _app():
    return QApplication.instance() or QApplication([])


def test_fetch_site_bg_parses_css_var_and_body():
    class _Resp:
        def __init__(self, text, code=200):
            self.text = text
            self.status_code = code

    class _Sess:
        def get(self, url, timeout=8):
            if url.endswith("/"):
                return _Resp('<html><head><link rel="stylesheet" href="/skins/test/style.css"></head></html>')
            return _Resp(":root { --bg-primary: #F4F1EA; } body { background-color: var(--bg-primary); }")

    conn = SumnaConnection("https://example.test", "k")
    conn.session = _Sess()
    assert conn.fetch_site_bg() == "#f4f1ea"


def test_normal_profile_change_applies_synced_site_bg_without_explicit_bg(tmp_path, monkeypatch):
    app = _app()
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    light_url = "https://light-site.example"
    dark_url = "https://dark-site.example"
    snap_library.sync_from_sybu_data(light_url, {"site_mode": "smacktalk", "site_bg": "#f4f1ea"})
    snap_library.sync_from_sybu_data(dark_url, {"site_mode": "smacktalk", "site_bg": "#111315"})

    profiles = {
        "Light": {"name": "Light", "url": light_url, "api_key": "a", "smackpress_key": "b", "site_mode": "smacktalk"},
        "Dark": {"name": "Dark", "url": dark_url, "api_key": "a", "smackpress_key": "b", "site_mode": "smacktalk"},
    }
    monkeypatch.setattr("coldsnap_qt.connect_panel.profile_manager.list_profiles", lambda: ["Light", "Dark"])
    monkeypatch.setattr("coldsnap_qt.connect_panel.profile_manager.load_profile", lambda n: profiles.get(n))
    monkeypatch.setattr("coldsnap_qt.connect_panel.profile_manager.save_profile", lambda *a, **kw: None)

    win = MainWindow()
    win.connect_panel._apply_loaded_sites({
        "config": {"url": light_url, "api_key": "a", "smackpress_key": "b"},
        "cache": {
            "Light": {"url": light_url, "mode": "smacktalk"},
            "Dark": {"url": dark_url, "mode": "smacktalk"},
        },
    })
    app.processEvents()

    assert win.connect_panel.select_site(light_url)
    app.processEvents()
    assert "#f4f1ea" in win.take_mode._canvas_scroll.styleSheet().lower()
    assert win.take_mode.body.canvas._ruler_color == "#333333"

    assert win.connect_panel.select_site(dark_url)
    app.processEvents()
    assert "#111315" in win.take_mode._canvas_scroll.styleSheet().lower()


def test_every_biggie_block_type_select_x_delete_and_ctrl_z_undo(tmp_path):
    app = _app()
    p = tmp_path / "one.jpg"
    Image.new("RGB", (1200, 800), (90, 90, 90)).save(p)

    initial_blocks = [
        {"type": "heading", "level": 2, "text": "Heading One"},
        {"type": "para", "text": "Paragraph One"},
        {"type": "quote", "text": "Quote One"},
        {"type": "list", "ordered": False, "items": ["Bullet One"]},
        {"type": "columns", "ratio": "equal", "cols": [[{"type": "para", "text": "Left"}], [{"type": "para", "text": "Right"}]]},
        {"type": "mosaic", "order": [1], "layout": "asymmetric"},
        {"type": "hr"},
        {"type": "spacer", "px": 24},
        {"type": "para", "text": "Tail Anchor"},
    ]

    for idx in range(len(initial_blocks) - 1):
        cv = BiggieCanvas()
        cv.setAttribute(Qt.WA_DontShowOnScreen, True)
        cv.resize(780, 900)
        cv.show()
        cv.set_bucket([str(p)])
        cv.from_blocks(initial_blocks)
        before = cv.to_blocks()
        assert len(before) == len(initial_blocks)

        # Find the document position for block index `idx`
        doc = cv.document()
        blk = doc.begin()
        seen_tables = set()
        current_idx = 0
        target_pos = None
        while blk.isValid():
            from PySide6.QtGui import QTextCursor
            tbl = QTextCursor(blk).currentTable()
            if tbl is not None:
                tpos = tbl.firstCursorPosition().position()
                if tpos not in seen_tables:
                    seen_tables.add(tpos)
                    if current_idx == idx:
                        target_pos = tpos
                        break
                    current_idx += 1
            else:
                if not blk.text().strip() and not cv._object_at(blk.position()):
                    blk = blk.next()
                    continue
                if current_idx == idx:
                    target_pos = blk.position()
                    break
                current_idx += 1
            blk = blk.next()

        assert target_pos is not None, f"Could not locate block {idx}"
        cv.select_block_at(target_pos)
        cv.viewport().repaint()
        app.processEvents()
        assert cv._block_close_rect is not None, f"Missing X close button for block {idx}"

        if idx % 2 == 0:
            # Delete via clicking the X button
            center = QPointF(cv._block_close_rect.center())
            ev = QMouseEvent(QEvent.MouseButtonPress, center, center, Qt.LeftButton, Qt.LeftButton, Qt.NoModifier)
            cv.mousePressEvent(ev)
        else:
            # Delete via Key_Delete
            cv.keyPressEvent(QKeyEvent(QKeyEvent.KeyPress, Qt.Key_Delete, Qt.NoModifier))

        after_del = cv.to_blocks()
        assert len(after_del) == len(before) - 1, f"Block {idx} ({before[idx]['type']}) was not deleted"

        cv.undo()
        after_undo = cv.to_blocks()
        assert after_undo == before, f"Undo did not restore block {idx} ({before[idx]['type']}) exactly"


# ===== SNAPSMACK EOF =====
