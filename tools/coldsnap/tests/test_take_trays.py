"""Tests for COLD TAKE collapsible right photos tray, thumb zoom, and laptop compaction."""

import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication, QWidget

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from coldsnap_qt.photo_tray import RightPhotosTray
from coldsnap_qt.mode_take import TakeMode


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


# ===== SNAPSMACK EOF =====
