"""COLD SNAP Qt — the main window: SITE header + three plain-words modes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

from PySide6.QtCore import QSettings, QTimer
from PySide6.QtGui import QIcon, QKeySequence, QShortcut
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QTabWidget, QLabel, QHBoxLayout,
)

from _version import BUILD_VERSION
import sumna_offline as O
import snap_desktop_handoff

from . import SHELL_LABEL
from .cold_storage import StorageMode
from .connect_panel import ConnectPanel
from .mode_solo import SoloMode
from .mode_stack import StackMode
from .mode_take import TakeMode
from .widgets import hint


class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"COLD SNAP — build {BUILD_VERSION} · {SHELL_LABEL}")
        self.resize(1240, 860)
        self.setMinimumSize(980, 680)
        self._window_settings = QSettings("SnapSmack", "COLD SNAP")
        geometry = self._window_settings.value("window/normal_geometry")
        if geometry:
            self.restoreGeometry(geometry)
        self._restore_maximized = self._window_settings.value("window/maximized", False, type=bool)

        icon_path = os.path.join(
            getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
            "assets", "coldsnap.ico")
        if os.path.isfile(icon_path):
            self.setWindowIcon(QIcon(icon_path))

        central = QWidget()
        col = QVBoxLayout(central)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(0)

        self.connect_panel = ConnectPanel()
        col.addWidget(self.connect_panel)

        cfg = lambda: self.connect_panel.config

        self.tabs = QTabWidget()
        self.tabs.setObjectName("ModeTabs")
        self.tabs.addTab(SoloMode(cfg), "COLD ONE")
        self.tabs.addTab(StackMode(cfg), "COLD STACK")
        self.tabs.addTab(TakeMode(cfg), "COLD TAKE")
        self.tabs.addTab(StorageMode(cfg), "COLD STORAGE")
        self.tabs.setTabToolTip(0, "One photo, one post — solo photoblog sites")
        self.tabs.setTabToolTip(1, "Carousels, stacks and trigrams — gram grid sites")
        self.tabs.setTabToolTip(2, "An essay with photos — long-form sites")
        self.tabs.setTabToolTip(3, "This blog's images, offline — browse, sync more "
                              "down, AI-describe, save metadata back")
        col.addWidget(self.tabs, 1)

        self._tab_modes = (
            (O.MODE_SOLO, "COLD ONE-compatible"),
            (O.MODE_GRAM, "COLD STACK-compatible"),
            (O.MODE_SMACKTALK, "COLD TAKE-compatible"),
            (None, ""),
        )
        self.tabs.currentChanged.connect(self._on_mode_changed)
        self._on_mode_changed(self.tabs.currentIndex())

        self.setCentralWidget(central)

        QShortcut(QKeySequence("F1"), self).activated.connect(
            self.connect_panel._show_help)

        self._handoff_timer = QTimer(self)
        self._handoff_timer.setInterval(600)
        self._handoff_timer.timeout.connect(self._consume_handoff)
        self._handoff_timer.start()
        QTimer.singleShot(0, self._consume_handoff)

        if self._restore_maximized:
            QTimer.singleShot(0, self.showMaximized)

    def _on_mode_changed(self, index):
        suite_mode, label = self._tab_modes[index]
        self.connect_panel.set_suite_mode(suite_mode, label)

    def _consume_handoff(self):
        request = snap_desktop_handoff.consume_request("coldsnap")
        if not request:
            return
        site_url = str(request.get("site_url") or "").strip()
        mode = str(request.get("site_mode") or "").strip().lower()
        if site_url and not self.connect_panel.select_site(site_url):
            self.statusBar().showMessage(
                "GYSS requested a site that is not saved in COLD SNAP.", 8000)
            return
        tab_for_mode = {"photoblog": 0, "carousel": 1, "smacktalk": 2}
        if mode in tab_for_mode:
            self.tabs.setCurrentIndex(tab_for_mode[mode])
        selected = list(request.get("selected_images") or [])
        if selected and mode == "smacktalk":
            self.tabs.widget(2).accept_handoff(selected)
        self.showNormal()
        self.raise_()
        self.activateWindow()
        self.statusBar().showMessage("Opened from GET YOUR SHIT SORTED", 5000)

    def closeEvent(self, event):
        if not self.isMinimized():
            self._window_settings.setValue("window/maximized", self.isMaximized())
            if not self.isMaximized():
                self._window_settings.setValue("window/normal_geometry", self.saveGeometry())
            self._window_settings.sync()
        super().closeEvent(event)

# ===== SNAPSMACK EOF =====
