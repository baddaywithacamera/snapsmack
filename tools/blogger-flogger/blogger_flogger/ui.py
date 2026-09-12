"""Qt review-and-import interface for BLOGGER FLOGGER."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import hashlib
import os
import sys
import threading
from pathlib import Path

from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QFileDialog,
    QFrame, QHBoxLayout, QLabel, QLineEdit, QListWidget, QListWidgetItem,
    QMainWindow, QMessageBox, QProgressBar, QPushButton, QSplitter, QTextBrowser,
    QTextEdit, QVBoxLayout, QWidget)

import snap_connections
import snap_creds
import snap_home
try:
    import snap_native_creds
except Exception:
    snap_native_creds = None

from _version import BUILD_VERSION
from .archive_reader import discover_archives, read_candidate
from .atom_parser import parse_feed
from .destination_client import DestinationClient
from .import_engine import ImportEngine
from .job_store import JobStore
from .report import write as write_report


STYLE = """
QWidget{background:#0d120f;color:#f4f7f2;font-family:'Segoe UI';font-size:14px}
QMainWindow{background:#090c0a} QFrame#header{background:#121a15;border-bottom:1px solid #29392f}
QFrame#card{background:#17221b;border:1px solid #2b3d31;border-radius:12px}
QLabel#brand{color:#73f04b;font-size:22px;font-weight:800} QLabel#muted{color:#8e9b8d}
QPushButton{background:#202e25;border:1px solid #3b5142;border-radius:8px;padding:10px 15px;font-weight:650}
QPushButton:hover{border-color:#73f04b;background:#283a2e} QPushButton:disabled{color:#606a61;background:#151a16}
QPushButton#primary{background:#73f04b;color:#071006;border-color:#73f04b;font-weight:850}
QLineEdit,QComboBox,QListWidget,QTextEdit,QTextBrowser{background:#090e0b;border:1px solid #2b3d31;border-radius:8px;padding:8px}
QProgressBar{background:#090e0b;border:1px solid #2b3d31;border-radius:6px;text-align:center}
QProgressBar::chunk{background:#73f04b;border-radius:5px}
"""


def _asset(name):
    root = getattr(sys, "_MEIPASS", str(Path(__file__).resolve().parents[1]))
    return os.path.join(root, "assets", name)


class Bridge(QObject):
    progress = Signal(int, int, str)
    log = Signal(str)
    finished = Signal(object)
    failed = Signal(str)


class Window(QMainWindow):
    def __init__(self):
        super().__init__(); self.setWindowTitle(f"BLOGGER FLOGGER — {BUILD_VERSION}")
        self.setWindowIcon(QIcon(_asset("blogger-flogger.png"))); self.resize(1320, 820)
        self.setMinimumSize(1040, 680); self.blog = None; self.candidates = []
        self.entries = {}; self.engine = None; self.store = None
        self.bridge = Bridge(); self.bridge.progress.connect(self._progress)
        self.bridge.finished.connect(self._finished); self.bridge.failed.connect(self._failed)
        self._build(); self._load_profiles()
        self.bridge.log.connect(self.activity.append)

    def _build(self):
        root = QWidget(); outer = QVBoxLayout(root); outer.setContentsMargins(0, 0, 0, 0); outer.setSpacing(0)
        header = QFrame(); header.setObjectName("header"); row = QHBoxLayout(header); row.setContentsMargins(22, 14, 22, 14)
        brand = QLabel("BLOGGER FLOGGER"); brand.setObjectName("brand"); row.addWidget(brand)
        row.addWidget(QLabel("Blogger Takeout → SMACKTALK")); row.addStretch(1)
        self.folder_btn = QPushButton("EXTRACTED FOLDER"); self.folder_btn.clicked.connect(self._choose_folder); row.addWidget(self.folder_btn)
        self.source_btn = QPushButton("TAKEOUT FILE"); self.source_btn.clicked.connect(self._choose); row.addWidget(self.source_btn)
        outer.addWidget(header)
        body = QSplitter(); left = QFrame(); ll = QVBoxLayout(left); ll.setContentsMargins(18, 18, 9, 18)
        ll.addWidget(QLabel("SOURCE BLOG")); self.feed_combo = QComboBox(); self.feed_combo.currentIndexChanged.connect(self._feed_changed); ll.addWidget(self.feed_combo)
        self.source_info = QLabel("Choose a Google Takeout ZIP, folder, feed.atom, or Blogger XML."); self.source_info.setObjectName("muted"); self.source_info.setWordWrap(True); ll.addWidget(self.source_info)
        ll.addWidget(QLabel("WHAT MOVES")); self.list = QListWidget(); self.list.currentItemChanged.connect(self._preview); ll.addWidget(self.list, 1)
        body.addWidget(left)
        middle = QFrame(); ml = QVBoxLayout(middle); ml.setContentsMargins(9, 18, 9, 18); ml.addWidget(QLabel("SOURCE PREVIEW")); self.preview = QTextBrowser(); ml.addWidget(self.preview, 1); body.addWidget(middle)
        right = QFrame(); rl = QVBoxLayout(right); rl.setContentsMargins(9, 18, 18, 18)
        rl.addWidget(QLabel("DESTINATION")); self.profile = QComboBox(); self.profile.currentIndexChanged.connect(self._profile_changed); rl.addWidget(self.profile)
        self.key = QLineEdit(); self.key.setEchoMode(QLineEdit.Password); self.key.setPlaceholderText("BLOGGER FLOGGER key"); rl.addWidget(self.key)
        save_key = QPushButton("SAVE KEY SECURELY"); save_key.clicked.connect(self._save_key); rl.addWidget(save_key)
        self.preserve = QCheckBox("Preserve published state (default is drafts)"); rl.addWidget(self.preserve)
        self.media = QCheckBox("Copy referenced photographs into SnapSmack"); self.media.setChecked(True); rl.addWidget(self.media)
        self.test_btn = QPushButton("TEST DESTINATION"); self.test_btn.clicked.connect(self._test); rl.addWidget(self.test_btn)
        self.status = QLabel("No destination tested."); self.status.setObjectName("muted"); self.status.setWordWrap(True); rl.addWidget(self.status)
        rl.addStretch(1); self.run_btn = QPushButton("FLOG IT"); self.run_btn.setObjectName("primary"); self.run_btn.setEnabled(False); self.run_btn.clicked.connect(self._run); rl.addWidget(self.run_btn)
        body.addWidget(right); body.setSizes([350, 610, 360]); outer.addWidget(body, 1)
        footer = QFrame(); fl = QVBoxLayout(footer); fl.setContentsMargins(22, 10, 22, 14)
        self.progress = QProgressBar(); self.progress.setRange(0, 100); fl.addWidget(self.progress)
        self.activity = QTextEdit(); self.activity.setReadOnly(True); self.activity.setMaximumHeight(95); fl.addWidget(self.activity); outer.addWidget(footer)
        self.setCentralWidget(root)

    def _load_profiles(self):
        self.profiles = snap_connections.list_connections("bloggerflogger")
        self.profile.clear()
        for profile in self.profiles: self.profile.addItem(profile["name"], profile)
        self._profile_changed()

    def _profile_changed(self):
        profile = self.profile.currentData()
        self.key.setText((profile or {}).get("api_key", "")); self.status.setText("Destination not tested.")
        self.run_btn.setEnabled(False)

    def _save_key(self):
        profile = self.profile.currentData(); key = self.key.text().strip()
        if not profile or not key: QMessageBox.warning(self, "Missing connection", "Choose a destination and enter its BLOGGER FLOGGER key."); return
        if snap_native_creds and snap_native_creds.set_site(profile["site_url"], "bloggerflogger", key):
            self.status.setText("Key saved in Windows Credential Manager.")
        else:
            snap_creds.set_site(profile["site_url"], "api_key_bloggerflogger", key)
            self.status.setText("Key saved in the shared encrypted credential vault.")

    def _choose(self):
        path, _ = QFileDialog.getOpenFileName(self, "Choose Blogger Takeout", "", "Blogger archive (*.zip *.atom *.xml);;All files (*)")
        if path: self._load_source(path)

    def _choose_folder(self):
        path = QFileDialog.getExistingDirectory(self, "Choose extracted Blogger Takeout folder")
        if path: self._load_source(path)

    def _load_source(self, path):
        try:
            self.candidates = discover_archives(path)
            if not self.candidates: raise ValueError("No feed.atom or Blogger XML was found.")
            self.feed_combo.clear()
            for candidate in self.candidates: self.feed_combo.addItem(candidate.label)
            self._feed_changed(0)
        except Exception as exc: QMessageBox.critical(self, "Could not open Takeout", str(exc))

    def _feed_changed(self, index=0):
        if index < 0 or index >= len(self.candidates): return
        try:
            self.blog = parse_feed(read_candidate(self.candidates[index])); self.entries = {e.source_id: e for e in self.blog.entries}
            self.list.clear()
            for entry in self.blog.entries:
                if entry.kind not in {"post", "page", "comment"}: continue
                item = QListWidgetItem(f"{entry.kind.upper()}  ·  {entry.title or entry.author_name or entry.source_id}")
                item.setData(Qt.UserRole, entry.source_id); item.setFlags(item.flags() | Qt.ItemIsUserCheckable); item.setCheckState(Qt.Checked); self.list.addItem(item)
            counts = self.blog.counts(); self.source_info.setText(f"{self.blog.title}\n{counts['post']} posts · {counts['page']} pages · {counts['comment']} comments")
            if self.list.count(): self.list.setCurrentRow(0)
        except Exception as exc: QMessageBox.critical(self, "Could not read feed", str(exc))

    def _preview(self, item, _old):
        if not item: return
        entry = self.entries.get(item.data(Qt.UserRole)); self.preview.setHtml(entry.content_html if entry else "")

    def _client(self):
        profile = self.profile.currentData()
        if not profile: raise RuntimeError("Choose a SnapSmack destination.")
        return DestinationClient(profile["site_url"], self.key.text().strip())

    def _test(self):
        try:
            result = self._client().preflight(); compatible = result.get("compatible")
            self.status.setText(f"Mode: {result.get('site_mode')} · Content: {result.get('content_count')} · " + ("Ready." if result.get("import_authorized") else "Import authorization required."))
            self.run_btn.setEnabled(bool(compatible and result.get("import_authorized") and self.blog))
        except Exception as exc: self.status.setText(str(exc)); self.run_btn.setEnabled(False)

    def _run(self):
        selected = {self.list.item(i).data(Qt.UserRole) for i in range(self.list.count()) if self.list.item(i).checkState() == Qt.Checked}
        if not selected: QMessageBox.warning(self, "Nothing selected", "Select at least one post, page, or comment."); return
        profile = self.profile.currentData(); token = hashlib.sha256((self.blog.source_id + profile["site_url"]).encode()).hexdigest()[:20]
        job_dir = Path(snap_home.config_dir("blogger-flogger"), "jobs", token); job_dir.mkdir(parents=True, exist_ok=True)
        self.store = JobStore(job_dir / "job.sqlite"); self.engine = ImportEngine(self.blog, self._client(), self.store,
            preserve_published=self.preserve.isChecked(), fetch_media=self.media.isChecked(),
            on_progress=lambda d, t, m: self.bridge.progress.emit(d, t, m), on_log=self.bridge.log.emit)
        self.run_btn.setEnabled(False); self.activity.append("Import started.")
        def work():
            try: self.bridge.finished.emit((self.engine.run(selected), job_dir))
            except Exception as exc: self.bridge.failed.emit(str(exc))
        threading.Thread(target=work, daemon=True).start()

    def _progress(self, done, total, message):
        self.progress.setValue(int(done * 100 / max(1, total))); self.activity.append(f"{done}/{total} — {message}")

    def _finished(self, payload):
        result, job_dir = payload; self.engine = None; self.run_btn.setEnabled(True)
        _manifest, report = write_report(job_dir, self.blog, result["summary"], self.blog.warnings)
        self.activity.append(f"Finished. Report: {report}"); QMessageBox.information(self, "Flogging complete", f"Reconciliation: {result['summary']}\n\nReport:\n{report}")
        if self.store: self.store.close(); self.store = None

    def _failed(self, message):
        self.engine = None; self.run_btn.setEnabled(True); self.activity.append("STOPPED — " + message); QMessageBox.critical(self, "Import stopped safely", message)
        if self.store: self.store.close(); self.store = None

    def closeEvent(self, event):
        if self.engine:
            answer = QMessageBox.question(self, "Import is running", "Stop safely and close?", QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes: event.ignore(); return
            self.engine.cancel()
        if self.store: self.store.close()
        event.accept()


def run():
    app = QApplication.instance() or QApplication(sys.argv); app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    app.setApplicationName("BLOGGER FLOGGER"); app.setWindowIcon(QIcon(_asset("blogger-flogger.png")))
    window = Window(); window.show(); return app.exec()

# ===== SNAPSMACK EOF =====
