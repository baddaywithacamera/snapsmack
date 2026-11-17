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

    for w, h in ((1920, 1080), (1440, 900), (1366, 768)):
        settle(w, h, photos=False, blocks=False, focus=False)
        assert win.take_mode._canvas_scroll.horizontalScrollBar().maximum() == 0

    for photos, blocks, focus in (
        (False, False, False),
        (True, False, False),
        (True, True, False),
        (False, False, True),
    ):
        settle(1024, 768, photos=photos, blocks=blocks, focus=focus)
        assert win.take_mode._canvas_scroll.horizontalScrollBar().maximum() == 0

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

    win.take_mode._set_thumb_zoom(100)
    win.take_mode._set_page_zoom(100)
    win.take_mode.set_photos_open(False)
    win.take_mode.set_blocks_open(False)
    win.close()


# ===== SNAPSMACK EOF =====
