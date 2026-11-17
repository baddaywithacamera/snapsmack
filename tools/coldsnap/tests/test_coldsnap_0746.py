"""Tests for COLD SNAP 0.7.46 features: mosaic packing, collapsible header, site bg, block delete+undo, zoom."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PIL import Image
from PySide6.QtCore import Qt
from PySide6.QtGui import QKeyEvent
from PySide6.QtWidgets import QApplication, QWidget

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from coldsnap_qt.canvas import BiggieCanvas, mosaic_cells
from coldsnap_qt.connect_panel import ConnectPanel
from coldsnap_qt.main_window import MainWindow
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt import theme


def _app():
    return QApplication.instance() or QApplication([])


def test_mosaic_mixed_and_similar_aspects_pack_into_multi_image_rows(tmp_path):
    _app()
    # 3 landscape, 2 portrait, 1 square, plus 4 near-identical landscape
    dims = [
        (1800, 1200), (1900, 1260), (2000, 1333),
        (1200, 1800), (1260, 1900),
        (1500, 1500),
        (2000, 1330), (2000, 1335), (2010, 1340), (1990, 1328),
    ]
    paths = []
    for i, (w, h) in enumerate(dims):
        p = tmp_path / f"m_{i}.jpg"
        Image.new("RGB", (w, h), (50, 80, 100)).save(p)
        paths.append(str(p))

    cv = BiggieCanvas()
    cv.set_bucket(paths)
    _paths, out = mosaic_cells(cv, {"order": list(range(1, 11)), "layout": "asymmetric"}, 800)
    assert len(out["items"]) == 10
    # No tile should span the full 800px width as a single-image row
    assert all(t["width"] < 790 for t in out["items"]), f"Single-image full-width row detected: {out['items']}"


def test_header_collapse_persists_and_shows_site_and_mode(monkeypatch):
    _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    win = MainWindow()
    win.set_header_collapsed(False)
    win.connect_panel.config["url"] = "https://baddaywithacamera.ca"
    win.tabs.setCurrentIndex(2)

    win.set_header_collapsed(True)
    assert win.connect_panel.isHidden()
    assert win.tabs.tabBar().isHidden()
    assert not win.header_strip.isHidden()
    assert "baddaywithacamera.ca" in win.header_strip.text()
    assert "COLD TAKE" in win.header_strip.text()

    # Persisted in QSettings
    assert bool(win._window_settings.value("window/header_collapsed", False, type=bool)) is True

    win.set_header_collapsed(False)
    assert not win.connect_panel.isHidden()
    assert not win.tabs.tabBar().isHidden()
    assert win.header_strip.isHidden()
    win.close()


def test_canvas_background_follows_site_and_contrasts_rulers():
    _app()
    take = TakeMode(lambda: {"url": ""})
    bg_light = take.apply_canvas_background(explicit_bg="#ffffff")
    assert bg_light == "#ffffff"
    assert take.body.canvas._ruler_color == "#333333"

    bg_fallback = take.apply_canvas_background(site_url="")
    assert bg_fallback == theme.BG
    assert take.body.canvas._ruler_color == theme.DIM

    # Zoom clamping & slider sync
    take._set_page_zoom(130)
    assert take._page_zoom == 130
    assert take.page_zoom_slider.value() == 130
    take._set_page_zoom(250)
    assert take._page_zoom == 160
    take._set_page_zoom(10)
    assert take._page_zoom == 70


def test_biggie_block_delete_and_ctrl_z_undo_round_trip(tmp_path):
    _app()
    p = tmp_path / "one.jpg"
    Image.new("RGB", (1200, 800), (90, 90, 90)).save(p)

    cv = BiggieCanvas()
    cv.set_bucket([str(p)])
    cv.from_blocks([
        {"type": "para", "text": "Before"},
        {"type": "mosaic", "order": [1], "layout": "asymmetric"},
        {"type": "para", "text": "After"},
    ])
    assert any(b["type"] == "mosaic" for b in cv.to_blocks())

    # Find mosaic object position, select it, delete via Key_Delete, then undo
    mosaic_pos = None
    blk = cv.document().begin()
    while blk.isValid():
        it = blk.begin()
        while not it.atEnd():
            frag = it.fragment()
            if frag.isValid() and frag.charFormat().objectType() != 0:
                mosaic_pos = frag.position()
                break
            it += 1
        if mosaic_pos is not None:
            break
        blk = blk.next()

    assert mosaic_pos is not None
    cv._selected_obj_pos = mosaic_pos
    cv.keyPressEvent(QKeyEvent(QKeyEvent.KeyPress, Qt.Key_Delete, Qt.NoModifier))
    assert not any(b["type"] == "mosaic" for b in cv.to_blocks())

    cv.undo()
    restored = [b for b in cv.to_blocks() if b["type"] == "mosaic"]
    assert len(restored) == 1
    assert restored[0]["order"] == [1]


# ===== SNAPSMACK EOF =====
