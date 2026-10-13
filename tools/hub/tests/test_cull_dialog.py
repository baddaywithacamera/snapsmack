"""PICK THE KEEPERS in the library: acts on the nominations, destroys nothing."""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import piexif
from PIL import Image, ImageDraw, ImageFilter
from PySide6.QtCore import Qt
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication

from slapper_qt import library_window

APP = QApplication.instance() or QApplication([])


def _wait_for(predicate, seconds=60):
    deadline = time.monotonic() + seconds
    while not predicate() and time.monotonic() < deadline:
        QTest.qWait(25)
        APP.processEvents()
    assert predicate()


def _frame(path, when, blur=0):
    photo = Image.new("RGB", (900, 600), (140, 150, 160))
    draw = ImageDraw.Draw(photo)
    for x in range(0, 900, 11):
        draw.line((x, 0, x, 600), fill=(15, 15, 15), width=2)
    if blur:
        photo = photo.filter(ImageFilter.GaussianBlur(blur))
    stamp = time.strftime("%Y:%m:%d %H:%M:%S", when).encode("ascii")
    exif = {"0th": {}, "Exif": {piexif.ExifIFD.DateTimeOriginal: stamp},
            "GPS": {}, "1st": {}, "thumbnail": None}
    photo.save(path, "JPEG", quality=92, exif=piexif.dump(exif))


def _library(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    folder = tmp_path / "shoot"
    folder.mkdir()
    base = time.localtime(1760000000)
    # One burst of three (the middle one sharp), then one lone frame later.
    for index, blur in enumerate((5, 0, 7)):
        _frame(folder / f"burst{index}.jpg",
               time.localtime(time.mktime(base) + index), blur)
    _frame(folder / "later.jpg", time.localtime(time.mktime(base) + 600))
    window = library_window.LibraryWindow()
    window.load_folder(str(folder))
    _wait_for(lambda: len(window._items) >= 4)
    return window, folder


def test_the_keeper_of_each_burst_is_selected_and_nothing_else_changes(
        tmp_path, monkeypatch):
    window, folder = _library(tmp_path, monkeypatch)
    try:
        before = {path.name: path.stat().st_mtime_ns for path in folder.iterdir()}
        from slapper_qt.cull_dialog import CullDialog
        dialog = CullDialog(window, window._paths, None)
        dialog._run()
        _wait_for(lambda: dialog._job is None and dialog.picks)
        # Two bursts: the three-frame one and the lone later frame.
        assert len(dialog.picks) == 2
        keepers = {os.path.basename(row["picked"]) for row in dialog.picks}
        assert keepers == {"burst1.jpg", "later.jpg"}
        selected = {os.path.basename(path)
                    for path in window._selected_photo_paths()}
        assert selected == keepers
        assert "left alone" in dialog.result.text()
        assert "Nothing was deleted, moved or renamed." in dialog.result.text()
        assert {path.name: path.stat().st_mtime_ns
                for path in folder.iterdir()} == before
        dialog.deleteLater()
    finally:
        window.close()


def test_marking_the_keepers_favourite_writes_only_to_the_catalogue(
        tmp_path, monkeypatch):
    window, folder = _library(tmp_path, monkeypatch)
    try:
        before = {path.name: path.stat().st_mtime_ns for path in folder.iterdir()}
        from slapper_qt.cull_dialog import CullDialog
        dialog = CullDialog(window, window._paths, None)
        dialog.outcome.setCurrentIndex(dialog.outcome.findData("favorite"))
        dialog._run()
        _wait_for(lambda: dialog._job is None and dialog.picks)
        keeper = str(folder / "burst1.jpg")
        assert window.catalog.details(keeper)["favorite"] is True
        # The frames it did not nominate are untouched in the catalogue too.
        assert window.catalog.details(str(folder / "burst0.jpg"))["favorite"] is False
        assert {path.name: path.stat().st_mtime_ns
                for path in folder.iterdir()} == before
        dialog.deleteLater()
    finally:
        window.close()


def test_rating_the_keepers_five_stars(tmp_path, monkeypatch):
    window, folder = _library(tmp_path, monkeypatch)
    try:
        from slapper_qt.cull_dialog import CullDialog
        dialog = CullDialog(window, window._paths, None)
        dialog.outcome.setCurrentIndex(dialog.outcome.findData("rate"))
        dialog._run()
        _wait_for(lambda: dialog._job is None and dialog.picks)
        assert window.catalog.details(str(folder / "burst1.jpg"))["rating"] == 5
        assert window.catalog.details(str(folder / "burst2.jpg"))["rating"] == 0
        dialog.deleteLater()
    finally:
        window.close()


def test_the_burst_window_is_adjustable(tmp_path, monkeypatch):
    window, _folder = _library(tmp_path, monkeypatch)
    try:
        from slapper_qt.cull_dialog import CullDialog
        dialog = CullDialog(window, window._paths, None)
        dialog.seconds.setValue(0.5)      # a second apart is no longer a burst
        dialog._run()
        _wait_for(lambda: dialog._job is None and dialog.picks)
        assert len(dialog.picks) == 4     # every frame is its own group
        dialog.deleteLater()
    finally:
        window.close()


def test_it_says_people_would_make_it_better_when_no_faces_are_known(
        tmp_path, monkeypatch):
    window, _folder = _library(tmp_path, monkeypatch)
    try:
        from slapper_qt.cull_dialog import CullDialog
        dialog = CullDialog(window, window._paths, None)
        assert "Install PEOPLE" in dialog.explain.text()
        assert "cannot tell you which photograph is best" in dialog.explain.text()
        dialog.deleteLater()

        class Store:
            photos = {"something": {"faces": [{"box": [0, 0, 10, 10]}]}}

        with_faces = CullDialog(window, window._paths, Store)
        assert "measured on the face" in with_faces.explain.text()
        with_faces.deleteLater()
    finally:
        window.close()


# ===== SNAPSMACK EOF =====
