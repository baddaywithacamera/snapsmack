"""The Qt library must be able to flag soft and very dark photographs.

photo_manager.quality_flags() existed all along and the Tk library called it;
the Qt port kept the duplicate finder beside it and left this one unreachable,
so the measured half of "which of these is the keeper" was missing from the
application Sean actually runs.
"""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image, ImageDraw, ImageFilter
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QMessageBox

import photo_manager
from slapper_qt import library_window


APP = QApplication.instance() or QApplication([])


def _sharp(path):
    photo = Image.new("RGB", (600, 400), (140, 150, 160))
    draw = ImageDraw.Draw(photo)
    for x in range(0, 600, 12):
        draw.line((x, 0, x, 400), fill=(10, 10, 10), width=3)
    photo.save(path, quality=95)


def _soft(path):
    photo = Image.new("RGB", (600, 400), (140, 150, 160))
    draw = ImageDraw.Draw(photo)
    for x in range(0, 600, 12):
        draw.line((x, 0, x, 400), fill=(10, 10, 10), width=3)
    photo.filter(ImageFilter.GaussianBlur(14)).save(path, quality=95)


def _dark(path):
    Image.new("RGB", (600, 400), (6, 6, 8)).save(path, quality=95)


def test_quality_flags_separates_soft_and_dark_from_sharp(tmp_path):
    _sharp(tmp_path / "sharp.jpg")
    _soft(tmp_path / "soft.jpg")
    _dark(tmp_path / "dark.jpg")
    results = photo_manager.quality_flags(
        [str(tmp_path / name) for name in ("sharp.jpg", "soft.jpg", "dark.jpg")])
    flagged = {os.path.basename(item["path"]): item["reasons"] for item in results}
    assert "sharp.jpg" not in flagged
    assert "possibly blurry" in flagged["soft.jpg"]
    assert "very dark" in flagged["dark.jpg"]


def test_the_library_scan_selects_the_flagged_photos_and_changes_nothing(tmp_path, monkeypatch):
    _sharp(tmp_path / "sharp.jpg")
    _soft(tmp_path / "soft.jpg")
    before = {path.name: path.stat().st_mtime_ns for path in tmp_path.iterdir()}
    monkeypatch.setattr(QMessageBox, "information",
                        lambda *args, **kwargs: QMessageBox.Ok)
    window = library_window.LibraryWindow()
    try:
        window.load_folder(str(tmp_path))
        deadline = time.monotonic() + 20
        while len(window._items) < 2 and time.monotonic() < deadline:
            QTest.qWait(20)
            APP.processEvents()
        assert len(window._paths) == 2
        window._find_quality_issues()
        APP.processEvents()
        selected = {os.path.basename(path) for path in window._selected_photo_paths()}
        assert selected == {"soft.jpg"}
        # A cull tool that edits the files is not a cull tool.
        after = {path.name: path.stat().st_mtime_ns for path in tmp_path.iterdir()}
        assert after == before
    finally:
        window.close()


def test_a_selection_survives_the_grid_rebuild(tmp_path, monkeypatch):
    """The last thumbnail settles the date sort by rebuilding the grid.

    That rebuild used to clear the selection, so pressing Find Exact
    Duplicates or Flag Blurry or Dark while thumbnails were still arriving
    left an empty selection a moment later.
    """
    _sharp(tmp_path / "one.jpg")
    _soft(tmp_path / "two.jpg")
    monkeypatch.setattr(QMessageBox, "information",
                        lambda *args, **kwargs: QMessageBox.Ok)
    window = library_window.LibraryWindow()
    try:
        window.load_folder(str(tmp_path))
        deadline = time.monotonic() + 20
        while len(window._items) < 2 and time.monotonic() < deadline:
            QTest.qWait(20)
            APP.processEvents()
        window._items[str(tmp_path / "two.jpg")].setSelected(True)
        window._populate()
        deadline = time.monotonic() + 20
        while len(window._items) < 2 and time.monotonic() < deadline:
            QTest.qWait(20)
            APP.processEvents()
        selected = {os.path.basename(path)
                    for path in window._selected_photo_paths()}
        assert selected == {"two.jpg"}
    finally:
        window.close()


# ===== SNAPSMACK EOF =====
