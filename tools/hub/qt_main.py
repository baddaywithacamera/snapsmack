"""Responsive Qt shell for SNAP HQ."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import os
import sys

from PySide6.QtCore import QObject, QSettings, QThread, Qt, Signal, Slot
from PySide6.QtGui import QGuiApplication, QIcon, QPixmap
from PySide6.QtWidgets import (QApplication, QCheckBox, QDialog, QFileDialog,
    QFrame, QGridLayout, QHBoxLayout, QLabel, QLineEdit, QMainWindow,
    QMessageBox, QProgressBar, QPushButton, QScrollArea, QVBoxLayout, QWidget)

import main as core
import snap_creds
import snap_discovery


STYLE = """
QWidget { background:#090b0a; color:#eef2ed; font-family:'Segoe UI'; font-size:13px; }
QMainWindow,QDialog { background:#090b0a; }
QFrame#header { background:#0e1310; border-bottom:1px solid #243028; }
QFrame#section { background:#101511; border:1px solid #29342c; border-radius:14px; }
QFrame#tool { background:#171d19; border:1px solid #27332b; border-radius:10px; }
QFrame#tool:hover { background:#202b23; border-color:#63ef3d; }
QLabel#brand { color:#63ef3d; font-size:27px; font-weight:900; }
QLabel#sectionTitle { color:#63ef3d; font-size:12px; font-weight:800; }
QLabel#toolTitle { font-size:13px; font-weight:800; }
QLabel#muted { color:#8d9a90; }
QPushButton { background:#1b241e; border:1px solid #334238; border-radius:8px;
              padding:8px 13px; font-weight:700; }
QPushButton:hover { border-color:#63ef3d; background:#243129; }
QPushButton#primary { background:#63ef3d; color:#071006; border-color:#63ef3d; }
QPushButton#quiet { background:transparent; border-color:transparent; color:#94a098; }
QLineEdit { background:#0b0f0c; border:1px solid #334238; border-radius:8px; padding:9px; }
QScrollArea { border:0; }
QCheckBox { spacing:9px; }
"""


MIGRATION_ROSTER = [
    ("SMACKPRESS", "WordPress → SMACKTALK", [os.path.join(core._R, "smackpress", "SmackPress.exe")]),
    ("BLOGGER FLOGGER", "Blogger → SMACKTALK", [os.path.join(core._R, "blogger-flogger", "blogger-flogger.exe")]),
    ("UNZUCKER", "Instagram → GRAMOFSMACK", [os.path.join(core._R, "unzucker", "unzucker.exe")]),
    ("FLKR FCKR", "Flickr → SMACKONEOUT", [os.path.join(core._R, "flkr-fckr", "flkr-fckr.exe")]),
]


def _icon_path(name: str) -> str:
    path = core._tool_image(name)
    return path if path and os.path.isfile(path) else ""


class DiscoveryWorker(QObject):
    finished = Signal(dict)
    failed = Signal(str)

    def __init__(self, hub_url: str, hub_key: str):
        super().__init__()
        self.hub_url = hub_url
        self.hub_key = hub_key

    @Slot()
    def run(self):
        try:
            self.finished.emit(snap_discovery.discover_and_save(
                self.hub_url, api_key=self.hub_key))
        except Exception as exc:
            self.failed.emit(str(exc))


class ToolCard(QFrame):
    def __init__(self, name, description, paths, parent=None):
        super().__init__(parent); self.setObjectName("tool"); self.setFixedHeight(82); self.setMinimumWidth(0)
        self.exe = core._find_exe(paths); self.name = name
        row = QHBoxLayout(self); row.setContentsMargins(16, 13, 13, 13); row.setSpacing(13)
        icon = QLabel(); icon.setFixedSize(42, 42); icon.setAlignment(Qt.AlignCenter)
        path = _icon_path(name)
        if path:
            icon.setPixmap(QPixmap(path).scaled(38, 38, Qt.KeepAspectRatio, Qt.SmoothTransformation))
        else:
            icon.setText("".join(word[0] for word in name.split()[:2]))
            icon.setStyleSheet("color:#63ef3d;font-size:17px;font-weight:900;border:1px solid #3a513f;border-radius:9px")
        row.addWidget(icon)
        words = QVBoxLayout(); words.setSpacing(3)
        title = QLabel(name); title.setObjectName("toolTitle"); title.setWordWrap(True); words.addWidget(title)
        sub = QLabel(description if self.exe else description + " · not installed"); sub.setObjectName("muted"); sub.setWordWrap(True); words.addWidget(sub)
        row.addLayout(words, 1)
        launch = QPushButton("OPEN" if self.exe else "MISSING"); launch.setEnabled(bool(self.exe))
        launch.clicked.connect(self._launch); row.addWidget(launch)

    def _launch(self):
        ok, error = core._launch(self.exe, parent=None)
        if not ok: QMessageBox.critical(self, "Could not open " + self.name, error)


class ToolSection(QFrame):
    def __init__(self, title, roster, parent=None):
        super().__init__(parent); self.setObjectName("section"); self.cards = []
        outer = QVBoxLayout(self); outer.setContentsMargins(15, 13, 15, 15); outer.setSpacing(10)
        label = QLabel(title); label.setObjectName("sectionTitle"); outer.addWidget(label)
        self.grid_host = QWidget(); self.grid = QGridLayout(self.grid_host)
        self.grid.setContentsMargins(0, 0, 0, 0); self.grid.setSpacing(10); outer.addWidget(self.grid_host)
        for row in roster: self.cards.append(ToolCard(*row))
        self.reflow(3)

    def reflow(self, columns):
        while self.grid.count(): self.grid.takeAt(0)
        for index, card in enumerate(self.cards):
            row, column = index // columns, index % columns
            if columns == 3 and index == len(self.cards) - 1 and len(self.cards) % columns == 1:
                column = 1
            self.grid.addWidget(card, row, column)
        for column in range(3): self.grid.setColumnStretch(column, 1 if column < columns else 0)


class SettingsDialog(QDialog):
    def __init__(self, settings, parent=None):
        super().__init__(parent); self.settings = settings
        self.setWindowTitle("SNAP HQ SETTINGS"); self.resize(720, 620); self.setMinimumSize(560, 480)
        outer = QVBoxLayout(self); outer.setContentsMargins(22, 20, 22, 20); outer.setSpacing(13)
        title = QLabel("SETTINGS"); title.setObjectName("brand"); outer.addWidget(title)
        hint = QLabel("Set it once. Every desktop tool uses it."); hint.setObjectName("muted"); outer.addWidget(hint)
        self.fields = {}
        for label, key, secret in (
            ("Hub site URL", "hub_url", False), ("Hub API key", "hub_key", True),
            ("Gemini API key", "gemini_api_key", True), ("Claude API key", "claude_api_key", True),
            ("OpenAI API key", "openai_api_key", True), ("Kimi API key", "kimi_api_key", True),
            ("DeepSeek API key", "deepseek_api_key", True),
            ("Google Drive credentials", "google_credentials", False),
            ("Backup folder ID", "drive_folder_id", True)):
            line = QHBoxLayout(); caption = QLabel(label); caption.setMinimumWidth(185); line.addWidget(caption)
            try: current = snap_creds.get(key, "")
            except Exception: current = ""
            edit = QLineEdit(current); edit.setEchoMode(QLineEdit.Password if secret else QLineEdit.Normal)
            line.addWidget(edit, 1); self.fields[key] = edit
            if key == "google_credentials":
                choose = QPushButton("CHOOSE"); choose.clicked.connect(lambda _=False, e=edit: self._choose(e)); line.addWidget(choose)
            outer.addLayout(line)
        self.migrations = QCheckBox("Show Migration Centre")
        self.migrations.setChecked(self.settings.value("showMigrationCentre", True, type=bool)); outer.addWidget(self.migrations)
        self.status = QLabel(""); self.status.setObjectName("muted"); outer.addWidget(self.status)
        self.progress = QProgressBar(); self.progress.setRange(0, 0)
        self.progress.setTextVisible(False); self.progress.hide(); outer.addWidget(self.progress)
        outer.addStretch(1)
        actions = QHBoxLayout(); save = QPushButton("SAVE"); save.clicked.connect(self._save); actions.addWidget(save)
        self.discover_button = QPushButton("DISCOVER FLEET"); self.discover_button.setObjectName("primary")
        self.discover_button.clicked.connect(self._discover); actions.addWidget(self.discover_button)
        actions.addStretch(1); close = QPushButton("DONE"); close.clicked.connect(self.accept); actions.addWidget(close); outer.addLayout(actions)

    def _choose(self, edit):
        path, _ = QFileDialog.getOpenFileName(self, "Choose Google credentials", "", "JSON (*.json);;All files (*)")
        if path: edit.setText(path)

    def _save(self):
        snap_creds.prepare_explicit_replacement()
        for key, edit in self.fields.items():
            value = edit.text().strip()
            if value: snap_creds.set(key, value)
            elif snap_creds.get(key, ""): snap_creds.delete(key)
        self.settings.setValue("showMigrationCentre", self.migrations.isChecked())
        self.status.setText("Saved to the shared protected store.")

    def _discover(self):
        if getattr(self, "discovery_thread", None):
            return
        self._save()
        self.status.setText("Connecting to the hub and discovering sites…")
        self.progress.show(); self.discover_button.setEnabled(False)
        self.discover_button.setText("DISCOVERING…")
        self.discovery_thread = QThread(self)
        self.discovery_worker = DiscoveryWorker(
            self.fields["hub_url"].text().strip(), self.fields["hub_key"].text().strip())
        self.discovery_worker.moveToThread(self.discovery_thread)
        self.discovery_thread.started.connect(self.discovery_worker.run)
        self.discovery_worker.finished.connect(self._discovery_finished)
        self.discovery_worker.failed.connect(self._discovery_failed)
        self.discovery_worker.finished.connect(self.discovery_thread.quit)
        self.discovery_worker.failed.connect(self.discovery_thread.quit)
        self.discovery_thread.finished.connect(self.discovery_worker.deleteLater)
        self.discovery_thread.finished.connect(self._discovery_cleanup)
        self.discovery_thread.start()

    @Slot(dict)
    def _discovery_finished(self, result):
        count = result.get("count", 0)
        self.status.setText(f"Discovery complete — saved {count} site{'s' if count != 1 else ''}.")

    @Slot(str)
    def _discovery_failed(self, message):
        self.status.setText("Discovery failed. Nothing was changed.")
        QMessageBox.critical(self, "Discovery failed", message)

    @Slot()
    def _discovery_cleanup(self):
        self.progress.hide(); self.discover_button.setEnabled(True)
        self.discover_button.setText("DISCOVER FLEET")
        self.discovery_thread.deleteLater()
        self.discovery_thread = None
        self.discovery_worker = None


class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.settings = QSettings("SnapSmack", "SNAP HQ")
        self.setWindowTitle(f"SNAP HQ — local desktop headquarters  ·  {core.BUILD_VERSION}")
        path = _icon_path("SNAP HQ")
        if path: self.setWindowIcon(QIcon(path))
        self.setMinimumSize(680, 460)
        root = QWidget(); shell = QVBoxLayout(root); shell.setContentsMargins(0, 0, 0, 0); shell.setSpacing(0)
        header = QFrame(); self.header = header; header.setObjectName("header"); line = QHBoxLayout(header); line.setContentsMargins(24, 16, 24, 16)
        brand = QLabel("SNAP HQ"); brand.setObjectName("brand"); line.addWidget(brand)
        tagline = QLabel("local desktop headquarters"); tagline.setObjectName("muted"); line.addWidget(tagline); line.addStretch(1)
        settings = QPushButton("SETTINGS"); settings.setObjectName("primary"); settings.clicked.connect(self._settings); line.addWidget(settings)
        shell.addWidget(header)
        scroll = QScrollArea(); self.scroll = scroll; scroll.setWidgetResizable(True)
        scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        content = QWidget(); self.content = content; content.setMinimumWidth(0); self.body = QVBoxLayout(content)
        self.body.setContentsMargins(20, 18, 20, 20); self.body.setSpacing(14)
        self.launch = ToolSection("LAUNCH", core.ROSTER); self.body.addWidget(self.launch)
        self.migration = ToolSection("MIGRATION CENTRE", MIGRATION_ROSTER); self.body.addWidget(self.migration)
        self.body.setAlignment(Qt.AlignTop); scroll.setWidget(content); shell.addWidget(scroll, 1); self.setCentralWidget(root)
        self._apply_settings(); self._fit_to_screen()

    def _fit_to_screen(self):
        available = QGuiApplication.primaryScreen().availableGeometry()
        width = min(1320, max(680, int(available.width() * .90)))
        # Establish the real column count first, then ask Qt how tall those
        # rendered sections are. This makes the lower 20px body margin match
        # the upper one instead of filling an arbitrary percentage of screen.
        self.resize(width, 460)
        # Before the window is shown, centralWidget() can still report its old
        # bootstrap width. Measure the column layout against the width we are
        # actually about to open at, or a phantom extra row inflates the window.
        self._reflow(width - 40)
        self.body.activate(); self.content.adjustSize()
        wanted = self.header.sizeHint().height() + self.content.sizeHint().height()
        height = min(max(460, wanted), max(460, int(available.height() * .88)))
        self.resize(width, height)
        self.move(available.center() - self.rect().center())

    def _settings(self):
        dialog = SettingsDialog(self.settings, self)
        dialog.exec(); self._apply_settings()

    def _apply_settings(self):
        self.migration.setVisible(self.settings.value("showMigrationCentre", True, type=bool))

    def _reflow(self, available_width=None):
        width = max(1, available_width if available_width is not None
                    else self.centralWidget().width() - 40)
        # A card needs roughly 380px for icon, useful title/description, and its
        # action. Choosing three columns sooner forces Qt to widen the content
        # behind the viewport and creates the clipped horizontal layout we ban.
        columns = 3 if width >= 1180 else 2 if width >= 760 else 1
        self.launch.reflow(columns); self.migration.reflow(columns)

    def resizeEvent(self, event):
        super().resizeEvent(event); self._reflow()


def run():
    app = QApplication.instance() or QApplication(sys.argv); app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    app.setApplicationName("SNAP HQ"); window = Window(); window.show(); return app.exec()


if __name__ == "__main__":
    import snap_single_instance
    if snap_single_instance.acquire("snap-hq", "SNAP HQ"):
        raise SystemExit(run())

# ===== SNAPSMACK EOF =====
