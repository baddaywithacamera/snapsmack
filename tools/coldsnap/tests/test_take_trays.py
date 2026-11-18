"""Tests for COLD TAKE collapsible right photos tray, thumb zoom, and laptop compaction."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt, QPointF
from PySide6.QtGui import QWheelEvent
from PySide6.QtWidgets import QApplication, QWidget

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from coldsnap_qt.photo_tray import RightPhotosTray
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.main_window import MainWindow
from coldsnap_qt.connect_panel import ConnectPanel


def _app():
    return QApplication.instance() or QApplication([])


def test_right_photos_tray_collapse_and_expand():
    _app()
    rail = QWidget()
    rail.setFixedWidth(360)
    tray = RightPhotosTray(rail)
    events = []
    tray.stateChanged.connect(events.append)

    assert not tray.is_open()
    assert tray.width() == 30

    tray.set_open(True)
    assert tray.is_open()
    assert tray.width() == 360
    assert events == [True]

    tray.toggle()
    assert not tray.is_open()
    assert tray.width() == 30
    assert events == [True, False]


def test_take_mode_thumb_zoom_and_single_line_save_state():
    _app()
    take = TakeMode(lambda: {})
    assert not take.save_state.wordWrap()

    take._set_thumb_zoom(150)
    assert take._thumb_zoom == 150
    assert take.thumb_zoom_lbl.text() == "150%"

    take._set_thumb_zoom(20)
    assert take._thumb_zoom == 60
    assert take.thumb_zoom_lbl.text() == "60%"

    take._set_thumb_zoom(100)
    assert take._thumb_zoom == 100


def test_focus_mode_and_compact_header(monkeypatch):
    _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())
    win = MainWindow()
    win.tabs.setCurrentIndex(2)
    win.take_mode.set_photos_open(True)
    win.take_mode.set_details_open(True)
    win.take_mode.set_blocks_open(True)

    win.set_focus_mode(True)
    assert win.is_focus_mode()
    assert not win.connect_panel.isVisible()
    assert not win.take_mode.photos_open()
    assert not win.take_mode.details_open()
    assert not win.take_mode.blocks_open()

    win.set_focus_mode(False)
    assert not win.is_focus_mode()
    assert win.take_mode.photos_open()
    assert win.take_mode.details_open()
    assert win.take_mode.blocks_open()

    win.resize(1366, 768)
    _app().processEvents()
    assert win.blog_btn.text() == "BLOG"
    assert win.photos_btn.text() == "PHOTOS"
    assert win.post_btn.text() == "POST"
    assert win.blocks_btn.text() == "BLOCKS"
    win.take_mode.set_photos_open(False)
    win.take_mode.set_details_open(False)
    win.take_mode.set_blocks_open(False)
    win.close()


def test_rendered_canvaswell_no_horizontal_overflow_and_ctrl_wheel(monkeypatch):
    app = _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    win = MainWindow()
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.tabs.setCurrentIndex(2)
    win.show()
    app.processEvents()

    def settle(w, h, photos=False, blocks=False, focus=False):
        win.set_focus_mode(focus)
        win.take_mode.set_photos_open(photos)
        win.take_mode.set_blocks_open(blocks)
        win.resize(w, h)
        for _ in range(3):
            app.processEvents()
            win.take_mode._fit_page_card_width()
            app.processEvents()

    for w, h in ((1920, 1080), (1440, 900), (1366, 768), (1024, 768)):
        for photos, blocks, focus in (
            (False, False, False),
            (True, False, False),
            (True, True, False),
            (False, False, True),
        ):
            settle(w, h, photos=photos, blocks=blocks, focus=focus)
            assert win.take_mode._canvas_scroll.horizontalScrollBar().maximum() == 0, (
                f"overflow at {w}x{h} photos={photos} blocks={blocks} focus={focus}"
            )

    # Slice 7: Ctrl+scroll zooms thumbnails when over bucket_host, page when over CanvasWell
    win.set_focus_mode(False)
    win.take_mode._set_thumb_zoom(100)
    win.take_mode._set_page_zoom(100)
    wheel_up = QWheelEvent(
        QPointF(10, 10), QPointF(10, 10),
        QWheelEvent.pixelDelta(QWheelEvent(QPointF(0, 0), QPointF(0, 0),
                                          QPointF(0, 0).toPoint(), QPointF(0, 120).toPoint(),
                                          Qt.NoButton, Qt.ControlModifier, Qt.NoScrollPhase, False)),
        QPointF(0, 120).toPoint(), Qt.NoButton, Qt.ControlModifier, Qt.NoScrollPhase, False,
    )
    app.sendEvent(win.take_mode.bucket_host, wheel_up)
    assert win.take_mode._thumb_zoom == 110
    assert win.take_mode._page_zoom == 100

    app.sendEvent(win.take_mode._canvas_scroll.viewport(), wheel_up)
    assert win.take_mode._page_zoom == 110
    assert win.take_mode._thumb_zoom == 110

    # Ctrl+0 reset: page resets via _reset_hovered_zoom when pointer is not over bucket_host,
    # and thumbnails reset when underMouse() is True on bucket_host.
    win.take_mode._reset_hovered_zoom()
    assert win.take_mode._page_zoom == 100
    assert win.take_mode._thumb_zoom == 110

    monkeypatch.setattr(win.take_mode.bucket_host, "underMouse", lambda: True)
    win.take_mode._reset_hovered_zoom()
    assert win.take_mode._thumb_zoom == 100

    win.take_mode._set_thumb_zoom(100)
    win.take_mode._set_page_zoom(100)
    win.take_mode.set_photos_open(False)
    win.take_mode.set_blocks_open(False)
    win.close()


def test_text_mode_switch_and_visual_text_roundtrip(monkeypatch):
    _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    win = MainWindow()
    win.tabs.setCurrentIndex(2)
    win.resize(1366, 768)

    # Switch to Text (TWIGGY) mode
    win.take_mode.body._set_biggie(False)
    assert not win.take_mode.body.is_biggie()
    assert win.take_mode.page_card.property("textView") is True
    assert win.take_mode.blocks_header_label() == "SHORTCODES"
    assert win.blocks_btn.text() == "SHORTCODES"
    assert "TEXT VIEW" in win.take_mode.page_eyebrow.text()

    # Round-trip Visual -> Text -> Visual -> Text across every block type
    from coldsnap_qt import biggie
    blocks = [
        {"type": "heading", "level": 2, "text": "Heading Two"},
        {"type": "heading", "level": 3, "text": "Heading Three"},
        {"type": "para", "text": "Plain paragraph text."},
        {"type": "para", "text": "Dropcap paragraph.", "dropcap": True},
        {"type": "quote", "text": "Quoted passage."},
        {"type": "pullquote", "text": "Lifted pullquote."},
        {"type": "hr"},
        {"type": "list", "ordered": False, "items": ["First bullet", "Second bullet"]},
        {"type": "list", "ordered": True, "items": ["Step one", "Step two"]},
        {"type": "image", "img_id": "42", "size": "full", "align": "center"},
        {"type": "spacer", "px": 24},
        {"type": "mosaic", "order": [1, 2, 3], "layout": "asymmetric"},
        {"type": "columns", "ratio": "equal", "cols": [
            [{"type": "para", "text": "Left col"}],
            [{"type": "para", "text": "Right col"}],
        ]},
        {"type": "raw", "text": "[site_name]"},
    ]
    win.take_mode.body._set_biggie(True)
    win.take_mode.body.biggie.from_blocks(blocks)
    win.take_mode.body._set_biggie(False)
    text_first = win.take_mode.body.editor.toPlainText()
    win.take_mode.body._set_biggie(True)
    blocks_roundtrip = win.take_mode.body.biggie.to_blocks()
    win.take_mode.body._set_biggie(False)
    text_second = win.take_mode.body.editor.toPlainText()

    assert text_first == text_second
    assert biggie.serialize_blocks(blocks_roundtrip) == text_first
    win.take_mode.body._set_biggie(True)
    win.close()


def test_14_photo_mosaic_no_nested_scrollbar_and_fast_scroll(tmp_path, monkeypatch):
    """14-photo MOSAIC renders without an inner BiggieCanvas scrollbar and scrolls < 100ms/step."""
    import time
    from PIL import Image
    import sumna_offline as O

    app = _app()
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    dummy_solo = QWidget()
    dummy_solo.refresh_taxonomy = lambda: None
    monkeypatch.setattr("coldsnap_qt.main_window.SoloMode", lambda _cfg: dummy_solo)
    monkeypatch.setattr("coldsnap_qt.main_window.StackMode", lambda _cfg: QWidget())
    monkeypatch.setattr("coldsnap_qt.main_window.StorageMode", lambda _cfg: QWidget())

    paths = []
    for i in range(14):
        p = tmp_path / f"photo_{i:02d}.jpg"
        Image.new("RGB", (2000, 1333), (i * 15, 90, 50)).save(p)
        paths.append(str(p))

    win = MainWindow()
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.tabs.setCurrentIndex(2)
    win.resize(1366, 768)
    win.show()

    win.take_mode.body._set_biggie(True)
    win.take_mode._bucket = [O.DraftImage(local_path=p, filename=os.path.basename(p)) for p in paths]
    win.take_mode._refresh_bucket()
    win.take_mode.body.canvas.from_blocks([
        {"type": "mosaic", "order": list(range(1, 15)), "layout": "asymmetric"}
    ])
    from PySide6.QtTest import QTest
    outer_sb = win.take_mode._canvas_scroll.verticalScrollBar()
    deadline = time.monotonic() + 3.0
    while time.monotonic() < deadline:
        app.processEvents()
        if outer_sb.maximum() > 0:
            break
        QTest.qWait(20)

    # No nested inner scrollbar on BiggieCanvas; outer CanvasWell handles scrolling
    assert win.take_mode.body.canvas.verticalScrollBar().maximum() == 0
    assert outer_sb.maximum() > 0

    # First scroll pass populates cached downscaled cell pixmaps
    for val in range(0, outer_sb.maximum() + 1, 240):
        outer_sb.setValue(val)
        app.processEvents()
    outer_sb.setValue(0)
    app.processEvents()

    max_step_ms = 0.0
    for val in range(0, outer_sb.maximum() + 1, 80):
        t0 = time.perf_counter()
        outer_sb.setValue(val)
        app.processEvents()
        dt = (time.perf_counter() - t0) * 1000.0
        if dt > max_step_ms:
            max_step_ms = dt

    assert max_step_ms < 100.0, f"UI-thread scroll stall {max_step_ms:.1f}ms >= 100ms"
    win.close()


# ===== SNAPSMACK EOF =====
