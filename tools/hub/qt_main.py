"""Responsive Qt shell for SNAP HQ."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import os
import sys

import requests

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


class CredentialTestWorker(QObject):
    finished = Signal(bool, str)

    def __init__(self, provider: str, key: str):
        super().__init__(); self.provider = provider; self.key = key

    @Slot()
    def run(self):
        try:
            if self.provider == "gemini":
                response = requests.get(
                    "https://generativelanguage.googleapis.com/v1beta/models",
                    params={"key": self.key}, timeout=20)
            elif self.provider == "claude":
                response = requests.get("https://api.anthropic.com/v1/models", headers={
                    "x-api-key": self.key, "anthropic-version": "2023-06-01"}, timeout=20)
            elif self.provider == "stability":
                response = requests.get("https://api.stability.ai/v1/user/balance", headers={
                    "Authorization": f"Bearer {self.key}"}, timeout=20)
            elif self.provider == "openai":
                response = requests.get("https://api.openai.com/v1/models", headers={
                    "Authorization": f"Bearer {self.key}"}, timeout=20)
            elif self.provider == "kimi":
                response = requests.get("https://api.moonshot.cn/v1/models", headers={
                    "Authorization": f"Bearer {self.key}"}, timeout=20)
            elif self.provider == "deepseek":
                response = requests.get("https://api.deepseek.com/v1/models", headers={
                    "Authorization": f"Bearer {self.key}"}, timeout=20)
            else:
                self.finished.emit(False, "Unknown provider."); return
            if response.ok:
                self.finished.emit(True, "Key accepted. No image or text was generated.")
                return
            detail = ""
            try:
                body = response.json()
                detail = str((body.get("error") or {}).get("message") or body.get("message") or "")
            except Exception:
                pass
            self.finished.emit(False, f"Rejected (HTTP {response.status_code})" +
                               (f" — {detail[:120]}" if detail else "."))
        except Exception as exc:
            self.finished.emit(False, str(exc)[:160])


class ToolCard(QFrame):
    def __init__(self, name, description, paths, parent=None):
        super().__init__(parent); self.setObjectName("tool"); self.setFixedHeight(82); self.setMinimumWidth(0)
        self.exe = core._find_exe(paths); self.name = name
        self.row = QHBoxLayout(self); self.row.setContentsMargins(16, 13, 13, 13); self.row.setSpacing(13)
        self.icon = QLabel(); self.icon.setFixedSize(42, 42); self.icon.setAlignment(Qt.AlignCenter)
        self.icon_path = _icon_path(name)
        if self.icon_path:
            self.icon.setPixmap(QPixmap(self.icon_path).scaled(38, 38, Qt.KeepAspectRatio, Qt.SmoothTransformation))
        else:
            self.icon.setText("".join(word[0] for word in name.split()[:2]))
        self.row.addWidget(self.icon)
        self.words = QVBoxLayout(); self.words.setSpacing(3)
        self.title = QLabel(name); self.title.setObjectName("toolTitle"); self.title.setWordWrap(True); self.words.addWidget(self.title)
        self.sub = QLabel(description if self.exe else description + " · not installed"); self.sub.setObjectName("muted"); self.sub.setWordWrap(True); self.words.addWidget(self.sub)
        self.row.addLayout(self.words, 1)
        self.launch = QPushButton("OPEN" if self.exe else "MISSING"); self.launch.setEnabled(bool(self.exe))
        self.launch.clicked.connect(self._launch); self.row.addWidget(self.launch)

    def apply_scale(self, scale):
        px = lambda value: max(1, round(value * scale))
        self.setFixedHeight(px(82)); self.row.setContentsMargins(px(16), px(13), px(13), px(13)); self.row.setSpacing(px(13)); self.words.setSpacing(px(3))
        self.icon.setFixedSize(px(42), px(42))
        if self.icon_path:
            self.icon.setPixmap(QPixmap(self.icon_path).scaled(px(38), px(38), Qt.KeepAspectRatio, Qt.SmoothTransformation))
        else:
            self.icon.setStyleSheet(f"color:#63ef3d;font-size:{px(17)}px;font-weight:900;border:1px solid #3a513f;border-radius:{px(9)}px")
        self.launch.setMinimumHeight(px(36)); self.launch.setMinimumWidth(px(64))

    def _launch(self):
        ok, error = core._launch(self.exe, parent=None)
        if not ok: QMessageBox.critical(self, "Could not open " + self.name, error)


class ToolSection(QFrame):
    def __init__(self, title, roster, parent=None):
        super().__init__(parent); self.setObjectName("section"); self.cards = []
        self.outer = QVBoxLayout(self); self.outer.setContentsMargins(15, 13, 15, 15); self.outer.setSpacing(10)
        label = QLabel(title); label.setObjectName("sectionTitle"); self.outer.addWidget(label)
        self.grid_host = QWidget(); self.grid = QGridLayout(self.grid_host)
        self.grid.setContentsMargins(0, 0, 0, 0); self.grid.setSpacing(10); self.outer.addWidget(self.grid_host)
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

    def apply_scale(self, scale):
        px = lambda value: max(1, round(value * scale))
        self.outer.setContentsMargins(px(15), px(13), px(15), px(15)); self.outer.setSpacing(px(10)); self.grid.setSpacing(px(10))
        for card in self.cards: card.apply_scale(scale)


class SettingsDialog(QDialog):
    def __init__(self, settings, parent=None):
        super().__init__(parent); self.settings = settings
        self.setWindowTitle("SNAP HQ SETTINGS"); self.resize(720, 620); self.setMinimumSize(560, 480)
        outer = QVBoxLayout(self); outer.setContentsMargins(22, 20, 22, 20); outer.setSpacing(13)
        title = QLabel("SETTINGS"); title.setObjectName("brand"); outer.addWidget(title)
        hint = QLabel("Set it once. Every desktop tool uses it."); hint.setObjectName("muted"); outer.addWidget(hint)
        self.fields = {}; self.credential_test = None
        testable = {
            "gemini_api_key": "gemini", "claude_api_key": "claude",
            "openai_api_key": "openai",
            "kimi_api_key": "kimi", "deepseek_api_key": "deepseek",
        }
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
            if key in testable:
                test = QPushButton("TEST")
                test.clicked.connect(
                    lambda _=False, p=testable[key], k=key, b=test:
                    self._test_credential(p, k, b))
                line.addWidget(test)
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

    def _test_credential(self, provider, key_name, button):
        if self.credential_test is not None:
            self.status.setText("Another credential test is already running."); return
        key = self.fields[key_name].text().strip()
        if not key:
            self.status.setText(f"{provider.title()}: enter a key first."); return
        self.status.setText(
            f"Testing the displayed {provider.title()} key without generating content…")
        button.setEnabled(False); button.setText("TESTING…")
        thread = QThread(self); worker = CredentialTestWorker(provider, key)
        worker.moveToThread(thread); thread.started.connect(worker.run)
        worker.finished.connect(
            lambda ok, message, p=provider: self._credential_test_finished(p, ok, message))
        worker.finished.connect(thread.quit); thread.finished.connect(worker.deleteLater)
        thread.finished.connect(lambda b=button: self._credential_test_cleanup(b))
        self.credential_test = (thread, worker); thread.start()

    def _credential_test_finished(self, provider, ok, message):
        self.status.setText(("✓ " if ok else "✗ ") + provider.title() + ": " + message)

    def _credential_test_cleanup(self, button):
        button.setEnabled(True); button.setText("TEST"); self.credential_test = None

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
        header = QFrame(); self.header = header; header.setObjectName("header"); self.header_line = QHBoxLayout(header); self.header_line.setContentsMargins(24, 16, 24, 16)
        brand = QLabel("SNAP HQ"); brand.setObjectName("brand"); self.header_line.addWidget(brand)
        tagline = QLabel("local desktop headquarters"); tagline.setObjectName("muted"); self.header_line.addWidget(tagline); self.header_line.addStretch(1)
        settings = QPushButton("SETTINGS"); settings.setObjectName("primary"); settings.clicked.connect(self._settings); self.header_line.addWidget(settings)
        shell.addWidget(header)
        scroll = QScrollArea(); self.scroll = scroll; scroll.setWidgetResizable(True)
        scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        content = QWidget(); self.content = content; content.setMinimumWidth(0); self.body = QVBoxLayout(content)
        self.body.setContentsMargins(20, 18, 20, 20); self.body.setSpacing(14)
        self.launch = ToolSection("LAUNCH", core.ROSTER); self.body.addWidget(self.launch)
        self.migration = ToolSection("MIGRATION CENTRE", MIGRATION_ROSTER); self.body.addWidget(self.migration)
        self.body.setAlignment(Qt.AlignTop); scroll.setWidget(content); shell.addWidget(scroll, 1); self.setCentralWidget(root)
        self._scale = 1.0; self._apply_settings(); self._fit_to_screen()

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

    def _apply_scale(self):
        width = max(680, self.centralWidget().width())
        height = max(460, self.centralWidget().height())
        # The normal 1320×704 window is 1:1. Maximising to a larger display grows
        # the complete composition by the smaller axis, preserving its proportions
        # instead of merely creating wider empty panels.
        scale = max(1.0, min(1.5, min(width / 1320.0, height / 704.0)))
        if abs(scale - self._scale) < .025: return
        self._scale = scale; px = lambda value: max(1, round(value * scale))
        app = QApplication.instance()
        if app:
            dynamic = STYLE.replace("font-size:13px", f"font-size:{px(13)}px").replace("font-size:27px", f"font-size:{px(27)}px").replace("font-size:12px", f"font-size:{px(12)}px")
            dynamic = dynamic.replace("padding:8px 13px", f"padding:{px(8)}px {px(13)}px").replace("padding:9px", f"padding:{px(9)}px")
            app.setStyleSheet(dynamic)
        self.header_line.setContentsMargins(px(24), px(16), px(24), px(16))
        self.body.setContentsMargins(px(20), px(18), px(20), px(20)); self.body.setSpacing(px(14))
        self.launch.apply_scale(scale); self.migration.apply_scale(scale)

    def resizeEvent(self, event):
        super().resizeEvent(event); self._reflow(); self._apply_scale()


def run():
    app = QApplication.instance() or QApplication(sys.argv); app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    app.setApplicationName("SNAP HQ"); window = Window(); window.show(); return app.exec()


if __name__ == "__main__":
    import snap_single_instance
    if snap_single_instance.acquire("snap-hq", "SNAP HQ"):
        raise SystemExit(run())

# ===== SNAPSMACK EOF =====
