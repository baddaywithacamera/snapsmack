"""Startup budget gate: MainWindow must become visible well under 5.0s."""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QApplication

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))


def test_startup_time_to_window_under_budget():
    app = QApplication.instance() or QApplication([])
    t0 = time.perf_counter()
    from coldsnap_qt.main_window import MainWindow

    win = MainWindow()
    win.setAttribute(Qt.WA_DontShowOnScreen, True)
    win.show()
    app.processEvents()
    elapsed = time.perf_counter() - t0
    print(f"STARTUP_TO_WINDOW_SEC={elapsed:.3f}")
    assert elapsed < 5.0, f"Startup to visible window took {elapsed:.3f}s (>= 5.0s budget)"
    win.close()


# ===== SNAPSMACK EOF =====
