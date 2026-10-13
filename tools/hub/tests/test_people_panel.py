"""PEOPLE in the library: scan, name once, then filter the grid by person.

Driven through the real library window. The scan itself runs on a worker
thread, so these wait for it the way the window does.
"""

import os
import shutil
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import pytest
from PySide6.QtCore import Qt
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication, QInputDialog, QMessageBox

import slapper_faces
from slapper_qt import faces_component, library_window

APP = QApplication.instance() or QApplication([])
HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHOTO = os.path.join(os.path.dirname(os.path.dirname(HUB)),
                     "projects", "snapsmack-ca", "img", "sean-paddleboard.jpg")
MODELS = os.environ.get("SLAPPER_FACE_MODELS", "")
HAS_CV2 = __import__("importlib").util.find_spec("cv2") is not None
REAL = pytest.mark.skipif(
    not (HAS_CV2 and MODELS and os.path.isfile(PHOTO)),
    reason="needs OpenCV, SLAPPER_FACE_MODELS and the sample photograph")


def _wait_for(predicate, seconds=120):
    deadline = time.monotonic() + seconds
    while not predicate() and time.monotonic() < deadline:
        QTest.qWait(25)
        APP.processEvents()
    assert predicate()


def _library(tmp_path, monkeypatch, photos=3):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    folder = tmp_path / "shoot"
    folder.mkdir()
    for index in range(photos):
        shutil.copy2(PHOTO, folder / f"frame{index}.jpg")
    monkeypatch.setattr(slapper_faces, "models_dir",
                        lambda directory=None: os.path.join(MODELS))
    window = library_window.LibraryWindow()
    window.load_folder(str(folder))
    _wait_for(lambda: len(window._items) >= photos)
    return window, folder


def test_the_panel_explains_the_component_when_it_is_not_installed(
        tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    monkeypatch.setattr(faces_component, "installed", lambda: False)
    monkeypatch.setitem(sys.modules, "cv2", None)   # as the frozen build sees it
    window = library_window.LibraryWindow()
    try:
        panel = window.people_panel
        import importlib
        monkeypatch.setattr(importlib.util, "find_spec", lambda name: None)
        panel.refresh()
        assert "120 MB" in panel.explain.text()
        assert "entirely on this computer" in panel.explain.text()
        assert panel.install_button.isVisible() or not panel.available()
    finally:
        window.close()


def test_the_privacy_promise_is_on_the_panel(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "home"))
    window = library_window.LibraryWindow()
    try:
        text = window.people_panel.privacy.text()
        assert "stay on this computer" in text
        assert "never altered" in text
    finally:
        window.close()


@REAL
def test_scan_name_and_filter_the_grid_by_person(tmp_path, monkeypatch):
    window, folder = _library(tmp_path, monkeypatch, photos=3)
    try:
        panel = window.people_panel
        assert panel.available()
        panel._scan()
        _wait_for(lambda: panel._job is None)
        # Three frames of the same person: one group, three faces.
        assert len(panel.store.photos) == 3
        assert panel.group_label.text().startswith("1 group")
        assert len(panel._group) == 3

        monkeypatch.setattr(QInputDialog, "getText",
                            staticmethod(lambda *a, **k: ("Sean", True)))
        panel._name_group()
        counts = panel.store.counts()
        assert counts == {"Sean": 3}
        assert panel.people.item(0).text().startswith("Sean — 3")

        # Clicking the name filters the grid to that person's photographs.
        panel._person_clicked(panel.people.item(0))
        APP.processEvents()
        shown = [window.list.item(i) for i in range(window.list.count())
                 if not window.list.item(i).isHidden()]
        assert len(shown) == 3
        # A photograph with nobody in it drops out of that view.
        from PIL import Image
        blank = folder / "nobody.jpg"
        Image.new("RGB", (800, 600), (20, 20, 20)).save(blank)
        window.load_folder(str(folder))
        _wait_for(lambda: len(window._items) >= 4)
        window.set_people_filter("Sean")
        APP.processEvents()
        shown = [window.list.item(i).data(Qt.UserRole) for i in range(window.list.count())
                 if not window.list.item(i).isHidden()]
        assert all(os.path.basename(path) != "nobody.jpg" for path in shown)
        assert len(shown) == 3
        window.set_people_filter(None)
        APP.processEvents()
        assert len([1 for i in range(window.list.count())
                    if not window.list.item(i).isHidden()]) == 4
    finally:
        window.close()


@REAL
def test_a_second_scan_skips_what_was_already_measured(tmp_path, monkeypatch):
    window, _folder = _library(tmp_path, monkeypatch, photos=2)
    try:
        panel = window.people_panel
        panel._scan()
        _wait_for(lambda: panel._job is None)
        first = window.statusBar().currentMessage()
        assert "Looked at 2" in first
        panel._scan()
        _wait_for(lambda: panel._job is None)
        # Nothing changed on disk, so nothing is re-measured.
        assert "Looked at 0" in window.statusBar().currentMessage()
        assert "2 already done" in window.statusBar().currentMessage()
    finally:
        window.close()


@REAL
def test_scanning_never_writes_to_the_photographs(tmp_path, monkeypatch):
    window, folder = _library(tmp_path, monkeypatch, photos=3)
    try:
        before = {path.name: path.stat().st_mtime_ns
                  for path in folder.iterdir()}
        panel = window.people_panel
        panel._scan()
        _wait_for(lambda: panel._job is None)
        after = {path.name: path.stat().st_mtime_ns for path in folder.iterdir()}
        assert after == before
    finally:
        window.close()


# ===== SNAPSMACK EOF =====
