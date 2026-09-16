import os
import sys
import threading
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

from PIL import Image
from PySide6.QtCore import QTimer
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication

import editor_engine
from slapper_qt import editor_window


APP = QApplication.instance() or QApplication([])


def _wait_for(predicate, milliseconds=3000):
    deadline = time.monotonic() + milliseconds / 1000
    while not predicate() and time.monotonic() < deadline:
        QTest.qWait(10)
        APP.processEvents()
    assert predicate()


def test_image_inspection_and_recovery_loading_do_not_block_ui(tmp_path, monkeypatch):
    source = tmp_path / "photo.png"
    Image.new("RGB", (80, 60), (20, 40, 60)).save(source)
    ui_thread = threading.get_ident()
    worker_threads = []
    original = editor_window.inspect_source

    def delayed(path):
        worker_threads.append(threading.get_ident())
        time.sleep(.12)
        return original(path)

    monkeypatch.setattr(editor_window, "inspect_source", delayed)
    window = editor_window.EditorWindow()
    ticks = []
    timer = QTimer(window); timer.setInterval(10); timer.timeout.connect(lambda: ticks.append(1))
    timer.start()
    started = time.perf_counter()
    assert window.open_path(source)
    returned_ms = (time.perf_counter() - started) * 1000
    assert returned_ms < 50
    _wait_for(lambda: window.doc is not None)
    assert len(ticks) >= 3
    assert worker_threads and all(item != ui_thread for item in worker_threads)
    window.close()


def test_project_extraction_runs_off_ui_thread(tmp_path, monkeypatch):
    source = tmp_path / "photo.png"
    project = tmp_path / "photo.slapper"
    Image.new("RGB", (80, 60), (80, 30, 10)).save(source)
    editor_engine.EditorDocument(source).save_project(project)
    ui_thread = threading.get_ident()
    calls = []
    original = editor_engine.EditorDocument.load_project

    def delayed(*args, **kwargs):
        calls.append(threading.get_ident())
        time.sleep(.12)
        return original(*args, **kwargs)

    monkeypatch.setattr(editor_engine.EditorDocument, "load_project", delayed)
    window = editor_window.EditorWindow()
    started = time.perf_counter()
    assert window.open_project_path(project)
    assert (time.perf_counter() - started) * 1000 < 50
    _wait_for(lambda: window.doc is not None)
    assert calls and all(item != ui_thread for item in calls)
    window.close()


# ===== SNAPSMACK EOF =====
