"""SMACK UP YOUR BACKUP — modern Qt desktop shell.

The backup/restore/cloud engines remain the authority. This module owns only
presentation, user intent, progress, and plain-language error reporting.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import threading
from datetime import datetime

from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtGui import QIcon, QFont
from PySide6.QtWidgets import (
    QApplication, QButtonGroup, QCheckBox, QComboBox, QFileDialog, QFrame,
    QHBoxLayout, QLabel, QLineEdit, QListWidget, QMainWindow, QMessageBox,
    QProgressBar, QPushButton, QScrollArea, QStackedWidget, QTextEdit,
    QVBoxLayout, QWidget,
)

import backup_engine
import config as config_module
import profile_manager
import restore_engine
from _version import BUILD_VERSION


INK = "#f4f7f2"
BODY = "#bac3b8"
DIM = "#778176"
VOID = "#090c0a"
BASE = "#0d120f"
PANEL = "#121a15"
CARD = "#18231c"
BORDER = "#28372d"
GREEN = "#73f04b"
GREEN_DARK = "#3ba525"
AMBER = "#ffbf47"
RED = "#ff6b6b"

STYLE = f"""
QWidget {{ background: {BASE}; color: {INK}; font-family: 'Segoe UI'; font-size: 14px; }}
QMainWindow {{ background: {VOID}; }}
QFrame#Sidebar {{ background: {VOID}; border-right: 1px solid {BORDER}; }}
QFrame#Header {{ background: {PANEL}; border-bottom: 1px solid {BORDER}; }}
QFrame#Card {{ background: {CARD}; border: 1px solid {BORDER}; border-radius: 12px; }}
QLabel#Eyebrow {{ color: {GREEN}; font-size: 11px; font-weight: 800; letter-spacing: 2px; }}
QLabel#Title {{ color: {INK}; font-size: 29px; font-weight: 750; }}
QLabel#PageTitle {{ color: {INK}; font-size: 24px; font-weight: 700; }}
QLabel#CardTitle {{ color: {INK}; font-size: 16px; font-weight: 700; }}
QLabel#Muted {{ color: {DIM}; }}
QLabel#StatusGood {{ color: {GREEN}; background: #142611; border: 1px solid #315d25; border-radius: 10px; padding: 5px 10px; font-weight: 700; }}
QLabel#StatusWarn {{ color: {AMBER}; background: #271f0f; border: 1px solid #5f4821; border-radius: 10px; padding: 5px 10px; font-weight: 700; }}
QPushButton {{ background: #202c24; border: 1px solid #34463a; border-radius: 8px; padding: 9px 14px; font-weight: 600; }}
QPushButton:hover {{ border-color: {GREEN_DARK}; background: #26372b; }}
QPushButton:disabled {{ color: #596259; background: #151a16; border-color: #232a24; }}
QPushButton#Primary {{ color: #071006; background: {GREEN}; border-color: {GREEN}; font-weight: 800; padding: 11px 18px; }}
QPushButton#Primary:hover {{ background: #8cff65; }}
QPushButton#Nav {{ text-align: left; background: transparent; border: 0; color: {BODY}; padding: 11px 14px; }}
QPushButton#Nav:checked {{ color: {GREEN}; background: #152219; border-left: 3px solid {GREEN}; }}
QLineEdit, QComboBox, QTextEdit, QListWidget {{ background: #0c110e; border: 1px solid {BORDER}; border-radius: 7px; padding: 8px; selection-background-color: {GREEN_DARK}; }}
QLineEdit:focus, QComboBox:focus, QTextEdit:focus {{ border-color: {GREEN}; }}
QProgressBar {{ background: #0b100d; border: 1px solid {BORDER}; border-radius: 7px; height: 13px; text-align: center; }}
QProgressBar::chunk {{ background: {GREEN}; border-radius: 6px; }}
QScrollArea {{ border: 0; }}
"""


def _icon_path():
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, "assets", "suyb.ico")


def _label(text, name=""):
    label = QLabel(text)
    if name:
        label.setObjectName(name)
    label.setWordWrap(True)
    return label


def _card(title, body=""):
    frame = QFrame(); frame.setObjectName("Card")
    layout = QVBoxLayout(frame); layout.setContentsMargins(18, 16, 18, 16); layout.setSpacing(9)
    layout.addWidget(_label(title, "CardTitle"))
    if body:
        layout.addWidget(_label(body, "Muted"))
    return frame, layout


class Bridge(QObject):
    progress = Signal(str, str, float)
    log = Signal(str)
    finished = Signal(object)
    tested = Signal(bool, str)
    restoreFinished = Signal(object)


class SuybWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"SMACK UP YOUR BACKUP — {BUILD_VERSION}")
        self.setWindowIcon(QIcon(_icon_path()))
        self.resize(1280, 840)
        self.setMinimumSize(1020, 680)
        self.bridge = Bridge()
        self.bridge.progress.connect(self._on_progress)
        self.bridge.log.connect(self._on_log)
        self.bridge.finished.connect(self._backup_done)
        self.bridge.tested.connect(self._test_done)
        self.bridge.restoreFinished.connect(self._restore_done)
        self.engine = None
        self.current_profile = None
        self._build()
        self._load_profiles()

    def _build(self):
        root = QWidget(); shell = QHBoxLayout(root); shell.setContentsMargins(0, 0, 0, 0); shell.setSpacing(0)
        side = QFrame(); side.setObjectName("Sidebar"); side.setFixedWidth(232)
        sl = QVBoxLayout(side); sl.setContentsMargins(18, 24, 18, 18); sl.setSpacing(4)
        sl.addWidget(_label("SNAPSMACK", "Eyebrow"))
        brand = _label("SMACK UP\nYOUR BACKUP", "CardTitle"); brand.setStyleSheet("font-size:20px;font-weight:850;")
        sl.addWidget(brand); sl.addSpacing(24)
        self.nav_group = QButtonGroup(self); self.nav_group.setExclusive(True)
        self.pages = QStackedWidget()
        entries = (("Overview", "Your safety net", self._overview_page),
                   ("Restore", "Bring a site back", self._restore_page),
                   ("Backups", "What you have", self._backups_page),
                   ("Connection", "Site and destination", self._settings_page))
        for index, (name, tip, factory) in enumerate(entries):
            button = QPushButton(f"{name}\n{tip}"); button.setObjectName("Nav"); button.setCheckable(True)
            button.clicked.connect(lambda _=False, i=index: self._show_page(i))
            self.nav_group.addButton(button); sl.addWidget(button)
            self.pages.addWidget(factory())
            if index == 0: button.setChecked(True)
        sl.addStretch(1)
        sl.addWidget(_label(f"BUILD {BUILD_VERSION}\nLocal-first · positively verified", "Muted"))
        shell.addWidget(side)

        main = QWidget(); ml = QVBoxLayout(main); ml.setContentsMargins(0, 0, 0, 0); ml.setSpacing(0)
        header = QFrame(); header.setObjectName("Header"); hl = QHBoxLayout(header); hl.setContentsMargins(24, 13, 24, 13)
        hl.addWidget(_label("SITE", "Eyebrow"))
        self.profile_combo = QComboBox(); self.profile_combo.setMinimumWidth(300)
        self.profile_combo.currentTextChanged.connect(self._profile_changed); hl.addWidget(self.profile_combo)
        self.connection = _label("Choose a site", "StatusWarn"); hl.addWidget(self.connection)
        hl.addStretch(1)
        test = QPushButton("Test connection"); test.clicked.connect(self._test_connection); hl.addWidget(test)
        ml.addWidget(header); ml.addWidget(self.pages, 1); shell.addWidget(main, 1)
        self.setCentralWidget(root)

    def _page(self, title, subtitle):
        scroll = QScrollArea(); scroll.setWidgetResizable(True)
        host = QWidget(); layout = QVBoxLayout(host); layout.setContentsMargins(28, 25, 28, 28); layout.setSpacing(16)
        layout.addWidget(_label(title, "PageTitle")); layout.addWidget(_label(subtitle, "Muted")); scroll.setWidget(host)
        return scroll, layout

    def _overview_page(self):
        page, layout = self._page("Your backup cockpit", "One clear view of the selected site, its latest safety copy, and what happens next.")
        summary, row = _card("Selected site")
        self.site_summary = _label("Choose a site above.", "Muted"); row.addWidget(self.site_summary)
        layout.addWidget(summary)
        actions = QHBoxLayout()
        for title, body in (("DATABASE", "Full SQL plus schema"), ("MEDIA", "Originals and site assets"), ("VERIFY", "Checksums before success")):
            card, col = _card(title, body); col.addStretch(1); actions.addWidget(card)
        layout.addLayout(actions)
        run, rl = _card("Make a fresh backup", "Differential is fast and downloads only changed files. Full rechecks the complete site.")
        opts = QHBoxLayout(); self.full_check = QCheckBox("Full backup"); opts.addWidget(self.full_check); opts.addStretch(1)
        self.run_btn = QPushButton("BACK UP THIS SITE"); self.run_btn.setObjectName("Primary"); self.run_btn.clicked.connect(self._run_backup); opts.addWidget(self.run_btn)
        rl.addLayout(opts); self.progress = QProgressBar(); self.progress.setRange(0, 100); rl.addWidget(self.progress)
        self.progress_text = _label("Ready when you are.", "Muted"); rl.addWidget(self.progress_text); layout.addWidget(run)
        activity, al = _card("Activity")
        self.log = QTextEdit(); self.log.setReadOnly(True); self.log.setMinimumHeight(210); self.log.setPlaceholderText("Backup activity will appear here in plain language.")
        al.addWidget(self.log); layout.addWidget(activity); layout.addStretch(1)
        return page

    def _restore_page(self):
        page, layout = self._page("Restore", "Choose a verified SUYB backup package. Nothing is sent until you review and confirm.")
        card, col = _card("Backup package", "Use a .zip created by SMACK UP YOUR BACKUP.")
        row = QHBoxLayout(); self.restore_path = QLineEdit(); self.restore_path.setPlaceholderText("Choose a local backup package…")
        choose = QPushButton("Choose file…"); choose.clicked.connect(self._choose_restore); row.addWidget(self.restore_path, 1); row.addWidget(choose); col.addLayout(row)
        self.restore_btn = QPushButton("REVIEW RESTORE"); self.restore_btn.setObjectName("Primary"); self.restore_btn.clicked.connect(self._run_restore); col.addWidget(self.restore_btn, 0, Qt.AlignRight)
        layout.addWidget(card); layout.addStretch(1); return page

    def _backups_page(self):
        page, layout = self._page("Backups on this computer", "Recent packages in the selected site's working folder.")
        card, col = _card("Recovery packages")
        self.backup_list = QListWidget(); self.backup_list.setMinimumHeight(380); col.addWidget(self.backup_list)
        refresh = QPushButton("Refresh"); refresh.clicked.connect(self._refresh_backups); col.addWidget(refresh, 0, Qt.AlignRight)
        layout.addWidget(card); layout.addStretch(1); return page

    def _settings_page(self):
        page, layout = self._page("Connection", "The same resolved backup key powers both testing and real backups.")
        card, col = _card("Selected site")
        self.name_edit = QLineEdit(); self.url_edit = QLineEdit(); self.key_edit = QLineEdit(); self.key_edit.setEchoMode(QLineEdit.Password)
        self.dir_edit = QLineEdit()
        for label, widget in (("Profile name", self.name_edit), ("Site URL", self.url_edit), ("Backup key", self.key_edit), ("Working folder", self.dir_edit)):
            col.addWidget(_label(label, "Muted")); col.addWidget(widget)
        buttons = QHBoxLayout(); buttons.addStretch(1)
        browse = QPushButton("Choose folder…"); browse.clicked.connect(self._choose_folder); buttons.addWidget(browse)
        save = QPushButton("Save connection"); save.setObjectName("Primary"); save.clicked.connect(self._save_profile); buttons.addWidget(save); col.addLayout(buttons)
        layout.addWidget(card); layout.addStretch(1); return page

    def _show_page(self, index):
        self.pages.setCurrentIndex(index)
        if index == 2: self._refresh_backups()

    def _load_profiles(self):
        self.profile_combo.blockSignals(True); self.profile_combo.clear()
        self.profile_combo.addItems(profile_manager.list_profiles())
        cfg = config_module.load(); wanted = cfg.get("app", "last_profile", fallback="")
        idx = self.profile_combo.findText(wanted)
        self.profile_combo.setCurrentIndex(idx if idx >= 0 else (0 if self.profile_combo.count() else -1))
        self.profile_combo.blockSignals(False)
        self._profile_changed(self.profile_combo.currentText())

    def _profile_changed(self, name):
        self.current_profile = profile_manager.load_profile(name) if name else None
        p = self.current_profile or {}
        self.name_edit.setText(str(p.get("name", ""))); self.url_edit.setText(str(p.get("site_url", "")))
        self.key_edit.setText(str(p.get("api_key", ""))); self.dir_edit.setText(str(p.get("backup_dir", "")))
        shown = str(p.get("site_url", "")).replace("https://", "").rstrip("/")
        self.site_summary.setText(f"{p.get('name', 'No site')}\n{shown or 'No URL'}\nLast successful run: {p.get('last_backup_date') or 'Not yet recorded'}")
        self.connection.setText("● Ready to verify" if p else "Choose a site")
        self.connection.setObjectName("StatusGood" if p else "StatusWarn"); self.connection.style().unpolish(self.connection); self.connection.style().polish(self.connection)
        self.run_btn.setEnabled(bool(p)); self._refresh_backups()

    def _resolved_key(self, profile):
        return config_module.effective_backup_key(profile)

    def _test_connection(self):
        p = dict(self.current_profile or {})
        if not p: return
        self.connection.setText("Checking…"); self.connection.setObjectName("StatusWarn")
        def work():
            try:
                key = self._resolved_key(p)
                if not key: raise RuntimeError("No usable backup key is available")
                session = backup_engine.SnapSmackSession(p["site_url"], api_key=key)
                response = session.session.get(p["site_url"].rstrip("/") + "/suyb-export.php?type=schema", timeout=20, allow_redirects=False, stream=True)
                code = response.status_code; ctype = response.headers.get("Content-Type", ""); response.close()
                if code != 200 or "sql" not in ctype.lower(): raise RuntimeError(f"Site returned HTTP {code}")
                self.bridge.tested.emit(True, "Connected · database export ready")
            except Exception as exc:
                self.bridge.tested.emit(False, str(exc))
        threading.Thread(target=work, daemon=True).start()

    def _test_done(self, ok, message):
        self.connection.setText(("● " if ok else "⚠ ") + message)
        self.connection.setObjectName("StatusGood" if ok else "StatusWarn"); self.connection.style().unpolish(self.connection); self.connection.style().polish(self.connection)

    def _global_cloud(self):
        cfg = config_module.load()
        return {"cloud_provider": cfg.get("cloud", "provider", fallback="google_drive"),
                "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback=""),
                "cloud_folder_id": cfg.get("cloud", "folder_id", fallback="")}

    def _run_backup(self):
        if not self.current_profile or self.engine: return
        p = dict(self.current_profile); p["api_key"] = self._resolved_key(p)
        self.log.clear(); self.run_btn.setEnabled(False); self.run_btn.setText("BACKUP IN PROGRESS…")
        self.engine = backup_engine.BackupEngine(p, force_full=self.full_check.isChecked(), global_cloud=self._global_cloud(),
            on_progress=lambda stage, msg, pct: self.bridge.progress.emit(stage, msg, pct),
            on_log=lambda msg: self.bridge.log.emit(str(msg)))
        engine = self.engine
        threading.Thread(target=lambda: self.bridge.finished.emit(engine.run()), daemon=True).start()

    def _on_progress(self, _stage, message, pct):
        self.progress.setValue(max(0, min(100, int(float(pct) * 100)))); self.progress_text.setText(message)

    def _on_log(self, message):
        self.log.append(message)

    def _backup_done(self, result):
        self.engine = None; self.run_btn.setEnabled(True); self.run_btn.setText("BACK UP THIS SITE")
        ok = bool((result or {}).get("success")); self.progress.setValue(100 if ok else self.progress.value())
        self.progress_text.setText("Backup completed and verified." if ok else "Backup needs attention. Details are above.")
        if ok:
            self.current_profile["last_backup_date"] = datetime.now().strftime("%Y-%m-%d %H:%M")
            profile_manager.save_profile(self.current_profile); self._profile_changed(self.current_profile["name"])
        else:
            errors = "\n".join((result or {}).get("errors", [])) or "The backup did not complete."
            QMessageBox.warning(self, "Backup needs attention", errors[:1800])

    def _choose_restore(self):
        path, _ = QFileDialog.getOpenFileName(self, "Choose SUYB backup", self.dir_edit.text(), "SUYB backup (*.zip)")
        if path: self.restore_path.setText(path)

    def _run_restore(self):
        path = self.restore_path.text().strip()
        if not self.current_profile or not os.path.isfile(path):
            QMessageBox.warning(self, "Choose a backup", "Choose an existing SUYB backup package first."); return
        if QMessageBox.question(self, "Restore this site?", f"Restore {self.current_profile['name']} from:\n{os.path.basename(path)}?\n\nThis writes files and database content to the selected site.", QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        self.restore_btn.setEnabled(False); self.restore_btn.setText("RESTORING…")
        restore_profile = dict(self.current_profile)
        restore_profile["api_key"] = self._resolved_key(restore_profile)
        self.engine = restore_engine.RestoreEngine(
            restore_profile, global_cloud=self._global_cloud(),
            on_progress=lambda stage, msg, pct: self.bridge.progress.emit(stage, msg, pct),
            on_log=lambda msg: self.bridge.log.emit(str(msg)))
        engine = self.engine
        threading.Thread(target=lambda: self.bridge.restoreFinished.emit(
            engine.restore_from_zip(path)), daemon=True).start()

    def _restore_done(self, result):
        self.engine = None; self.restore_btn.setEnabled(True); self.restore_btn.setText("REVIEW RESTORE")
        if (result or {}).get("success"):
            QMessageBox.information(self, "Restore complete", "The site was restored and the operation completed successfully.")
        else:
            errors = "\n".join((result or {}).get("errors", [])) or "The restore did not complete."
            QMessageBox.warning(self, "Restore needs attention", errors[:1800])

    def _refresh_backups(self):
        if not hasattr(self, "backup_list"): return
        self.backup_list.clear(); folder = str((self.current_profile or {}).get("backup_dir", ""))
        if not os.path.isdir(folder): self.backup_list.addItem("No local backup folder yet."); return
        files = [os.path.join(folder, name) for name in os.listdir(folder) if name.lower().endswith(".zip")]
        for path in sorted(files, key=os.path.getmtime, reverse=True)[:100]:
            stamp = datetime.fromtimestamp(os.path.getmtime(path)).strftime("%Y-%m-%d  %H:%M")
            self.backup_list.addItem(f"{stamp}    {os.path.getsize(path) / 1048576:.1f} MB    {os.path.basename(path)}")
        if not files: self.backup_list.addItem("No local recovery packages yet.")

    def _choose_folder(self):
        path = QFileDialog.getExistingDirectory(self, "Choose backup working folder", self.dir_edit.text())
        if path: self.dir_edit.setText(path)

    def _save_profile(self):
        if not self.current_profile: return
        p = dict(self.current_profile); old_name = p.get("name", "")
        p.update({"name": self.name_edit.text().strip(), "site_url": self.url_edit.text().strip().rstrip("/"),
                  "api_key": self.key_edit.text().strip(), "backup_dir": self.dir_edit.text().strip()})
        if not p["name"] or not p["site_url"]:
            QMessageBox.warning(self, "Missing details", "Profile name and site URL are required."); return
        profile_manager.save_profile(p); self.current_profile = p
        self._load_profiles(); self.profile_combo.setCurrentText(p["name"])
        self.connection.setText("● Saved · ready to verify"); self.connection.setObjectName("StatusGood")

    def closeEvent(self, event):
        if self.engine:
            if QMessageBox.question(self, "Backup is running", "Stop the running backup and close?", QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
                event.ignore(); return
            self.engine.cancel()
        event.accept()


def run():
    app = QApplication.instance() or QApplication(sys.argv)
    app.setApplicationName("SMACK UP YOUR BACKUP")
    app.setWindowIcon(QIcon(_icon_path()))
    app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    font = QFont("Segoe UI", 10); app.setFont(font)
    window = SuybWindow(); window.show()
    return app.exec()

# ===== SNAPSMACK EOF =====
