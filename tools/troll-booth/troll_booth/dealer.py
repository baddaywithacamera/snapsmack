from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

from PySide6.QtCore import Qt
from PySide6.QtGui import QFont
from PySide6.QtWidgets import QApplication, QFrame, QGridLayout, QLabel, QMainWindow, QPushButton, QVBoxLayout, QWidget


class DealerWindow(QMainWindow):
    def __init__(self) -> None:
        super().__init__()
        self.setWindowTitle("SMACK DEALER")
        self.resize(820, 520)
        root = QWidget()
        box = QVBoxLayout(root)
        title = QLabel("SMACK DEALER")
        title.setFont(QFont("Segoe UI", 24, QFont.Weight.Black))
        title.setStyleSheet("color:#72ff48;letter-spacing:3px")
        subtitle = QLabel("ADMIN APPLICATIONS")
        subtitle.setStyleSheet("color:#93a894;letter-spacing:2px")
        box.addWidget(title)
        box.addWidget(subtitle)
        grid = QGridLayout()
        grid.addWidget(self._tile("TROLL BOOTH", "Agent bridge, shared rooms, CLI connections", self.launch_troll_booth), 0, 0)
        grid.addWidget(self._tile("CRONOMETER", "Scheduled operations", None), 0, 1)
        box.addLayout(grid, 1)
        self.setCentralWidget(root)
        self.setStyleSheet("QWidget{background:#0a0f0b;color:#edf7ee;font:11pt 'Segoe UI'}")

    def _tile(self, name: str, description: str, action) -> QFrame:
        frame = QFrame()
        frame.setStyleSheet("QFrame{background:#111b14;border:1px solid #29432e;border-radius:8px}")
        box = QVBoxLayout(frame)
        heading = QLabel(name)
        heading.setStyleSheet("color:#72ff48;font-size:17pt;font-weight:800;border:0")
        text = QLabel(description)
        text.setWordWrap(True)
        text.setStyleSheet("color:#b4c6b6;border:0")
        button = QPushButton("OPEN" if action else "NOT INSTALLED")
        button.setEnabled(action is not None)
        button.setStyleSheet("QPushButton{background:#72ff48;color:#071009;border:0;border-radius:5px;padding:10px;font-weight:800} QPushButton:disabled{background:#263328;color:#738075}")
        if action:
            button.clicked.connect(action)
        box.addWidget(heading)
        box.addWidget(text)
        box.addStretch()
        box.addWidget(button)
        return frame

    def launch_troll_booth(self) -> None:
        packaged = Path(sys.executable).with_name("TROLL BOOTH.exe")
        if getattr(sys, "frozen", False) and packaged.exists():
            command = [str(packaged)]
        else:
            command = [str(Path(sys.executable)), "-m", "troll_booth.desktop"]
        flags = 0
        if os.name == "nt":
            flags = subprocess.CREATE_NEW_PROCESS_GROUP | subprocess.DETACHED_PROCESS
        subprocess.Popen(command, creationflags=flags, close_fds=True)


def main() -> None:
    app = QApplication(sys.argv)
    app.setApplicationName("SMACK DEALER")
    window = DealerWindow()
    window.show()
    raise SystemExit(app.exec())


if __name__ == "__main__":
    main()
