"""COLD SNAP Qt — the main window: SITE header + three plain-words modes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

from PySide6.QtGui import QIcon, QKeySequence, QShortcut
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QTabWidget, QLabel, QHBoxLayout,
)

from _version import BUILD_VERSION

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

        icon_path = os.path.join(
            getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
            "assets", "coldsnap.ico")
        if os.path.isfile(icon_path):
            self.setWindowIcon(QIcon(icon_path))

        central = QWidget()
        col = QVBoxLayout(central)
        col.setContentsMargins(12, 12, 12, 8)
        col.setSpacing(10)

        self.connect_panel = ConnectPanel()
        col.addWidget(self.connect_panel)

        cfg = lambda: self.connect_panel.config

        tabs = QTabWidget()
        tabs.addTab(SoloMode(cfg), "COLD ONE")
        tabs.addTab(StackMode(cfg), "COLD STACK")
        tabs.addTab(TakeMode(cfg), "COLD TAKE")
        tabs.addTab(StorageMode(cfg), "COLD STORAGE")
        tabs.setTabToolTip(0, "One photo, one post — solo photoblog sites")
        tabs.setTabToolTip(1, "Carousels, stacks and trigrams — gram grid sites")
        tabs.setTabToolTip(2, "An essay with photos — long-form sites")
        tabs.setTabToolTip(3, "This blog's images, offline — browse, sync more "
                              "down, AI-describe, save metadata back")
        col.addWidget(tabs, 1)

        self.setCentralWidget(central)

        QShortcut(QKeySequence("F1"), self).activated.connect(
            self.connect_panel._show_help)

# ===== SNAPSMACK EOF =====
