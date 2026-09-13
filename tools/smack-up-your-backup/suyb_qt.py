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
import time
from datetime import datetime

from PySide6.QtCore import QObject, Qt, Signal, QTimer, QUrl
from PySide6.QtGui import QAction, QDesktopServices, QIcon, QFont, QKeySequence
from PySide6.QtWidgets import (
    QAbstractItemView, QApplication, QButtonGroup, QCheckBox, QComboBox,
    QDialog, QDialogButtonBox, QFileDialog, QFrame, QHBoxLayout, QLabel,
    QLineEdit, QListWidget, QMainWindow, QMenu, QMessageBox, QProgressBar,
    QPushButton, QScrollArea, QSizePolicy, QStackedWidget, QTextEdit,
    QSystemTrayIcon, QVBoxLayout, QWidget,
)

import backup_engine
from checkpoint import BackupCheckpoint
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
    stats = Signal(str, int, int, int, int, int, int)
    log = Signal(str)
    finished = Signal(object)
    tested = Signal(bool, str)
    restoreFinished = Signal(object)


class FitScrollArea(QScrollArea):
    """Fill the viewport until the page reaches its genuine minimum height."""
    def __init__(self):
        super().__init__(); self.setWidgetResizable(False)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)

    def setWidget(self, widget):
        super().setWidget(widget); self._fit_widget()

    def resizeEvent(self, event):
        super().resizeEvent(event); self._fit_widget()

    def _fit_widget(self):
        widget = self.widget()
        if widget:
            widget.resize(self.viewport().width(), max(self.viewport().height(), widget.minimumSizeHint().height()))


class SuybWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"SMACK UP YOUR BACKUP — {BUILD_VERSION}")
        self.setWindowIcon(QIcon(_icon_path()))
        self.resize(1280, 840)
        self.setMinimumSize(1020, 680)
        self.bridge = Bridge()
        self.bridge.progress.connect(self._on_progress)
        self.bridge.stats.connect(self._on_stats)
        self.bridge.log.connect(self._on_log)
        self.bridge.finished.connect(self._backup_done)
        self.bridge.tested.connect(self._test_done)
        self.bridge.restoreFinished.connect(self._restore_done)
        self.engine = None
        self._pause_requested = False
        self._backup_started = None
        self._site_started = None
        self._stats_site = ""
        self._last_pct = 0.0
        self._last_stats = None
        self._clock = QTimer(self); self._clock.setInterval(1000); self._clock.timeout.connect(self._refresh_stats)
        self.current_profile = None
        self.selected_profile_names = []
        self._build()
        self._build_tray()
        self._load_profiles()
        help_action = QAction("Help", self)
        help_action.setShortcut(QKeySequence.HelpContents)
        help_action.triggered.connect(self._show_help)
        self.addAction(help_action)

    def _build_tray(self):
        self.tray = None
        self.tray_pause_action = None
        if not QSystemTrayIcon.isSystemTrayAvailable():
            return
        self.tray = QSystemTrayIcon(QIcon(_icon_path()), self)
        self.tray.setToolTip("SMACK UP YOUR BACKUP — ready")
        menu = QMenu(self)
        open_action = QAction("Open SMACK UP YOUR BACKUP", self)
        open_action.triggered.connect(self._show_window)
        menu.addAction(open_action)
        self.tray_pause_action = QAction("Pause backup", self)
        self.tray_pause_action.setEnabled(False)
        self.tray_pause_action.triggered.connect(self._toggle_pause)
        menu.addAction(self.tray_pause_action)
        menu.addSeparator()
        quit_action = QAction("Quit", self)
        quit_action.triggered.connect(self._quit_from_tray)
        menu.addAction(quit_action)
        self.tray.setContextMenu(menu)
        self.tray.activated.connect(self._tray_activated)
        self.tray.show()

    def _tray_activated(self, reason):
        if reason in (QSystemTrayIcon.DoubleClick, QSystemTrayIcon.Trigger):
            self._show_window()

    def _show_window(self):
        self.showNormal()
        self.raise_()
        self.activateWindow()

    def _tray_message(self, title, message, critical=False):
        if self.tray:
            icon = QSystemTrayIcon.Critical if critical else QSystemTrayIcon.Information
            self.tray.showMessage(title, message, icon, 8000)

    def _quit_from_tray(self):
        self._show_window()
        if self.engine and QMessageBox.question(
                self, "Quit during backup?",
                "Quit now? The current run will stop, but its verified progress "
                "will remain available to resume next time.",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        if self.engine:
            self.engine.cancel()
        if self.tray:
            self.tray.hide()
        QApplication.instance().quit()

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
        self.choose_sites_btn = QPushButton("Choose sites…")
        self.choose_sites_btn.clicked.connect(self._choose_sites); hl.addWidget(self.choose_sites_btn)
        hl.addStretch(1)
        self.connection = _label("Choose a site", "StatusWarn")
        self.connection.setWordWrap(False)
        hl.addWidget(self.connection)
        help_btn = QPushButton("HELP · F1"); help_btn.clicked.connect(self._show_help); hl.addWidget(help_btn)
        test = QPushButton("Test connection"); test.clicked.connect(self._test_connection); hl.addWidget(test)
        ml.addWidget(header); ml.addWidget(self.pages, 1); shell.addWidget(main, 1)
        self.setCentralWidget(root)

    def _page(self, title, subtitle):
        scroll = FitScrollArea()
        host = QWidget(); host.setSizePolicy(QSizePolicy.Expanding, QSizePolicy.Ignored)
        layout = QVBoxLayout(host); layout.setContentsMargins(28, 25, 28, 28); layout.setSpacing(16)
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
        self.pause_btn = QPushButton("PAUSE")
        self.pause_btn.setEnabled(False)
        self.pause_btn.clicked.connect(self._toggle_pause)
        opts.addWidget(self.pause_btn)
        self.run_btn = QPushButton("BACK UP THIS SITE"); self.run_btn.setObjectName("Primary"); self.run_btn.clicked.connect(self._run_backup); opts.addWidget(self.run_btn)
        rl.addLayout(opts); self.progress = QProgressBar(); self.progress.setRange(0, 100); rl.addWidget(self.progress)
        stats = QHBoxLayout()
        self.files_stats = _label("FILES\nWaiting for inventory", "Muted")
        self.data_stats = _label("DATA\nWaiting for inventory", "Muted")
        self.time_stats = _label("TIME\nNot started", "Muted")
        stats.addWidget(self.files_stats, 1); stats.addWidget(self.data_stats, 1); stats.addWidget(self.time_stats, 1)
        rl.addLayout(stats)
        self.progress_text = _label("Ready when you are.", "Muted"); rl.addWidget(self.progress_text); layout.addWidget(run)
        activity, al = _card("Activity"); self.activity_card = activity
        self.log = QTextEdit(); self.log.setReadOnly(True); self.log.setMinimumHeight(72); self.log.setPlaceholderText("Backup activity will appear here in plain language.")
        # Activity owns the remaining page height. A stretch item after the card
        # created a dead band at the bottom while the useful log stayed short.
        al.addWidget(self.log); layout.addWidget(activity, 1)
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
        buttons = QHBoxLayout(); buttons.addStretch(1)
        self.open_backup_folder_btn = QPushButton("Open backup folder")
        self.open_backup_folder_btn.clicked.connect(self._open_backup_folder); buttons.addWidget(self.open_backup_folder_btn)
        refresh = QPushButton("Refresh"); refresh.clicked.connect(self._refresh_backups); buttons.addWidget(refresh)
        col.addLayout(buttons)
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

    def _show_help(self):
        topics = [
            ("Making a backup",
             "Choose one site above, or use Choose sites… to select several.\n\n"
             "Differential downloads only files that are new or changed. Full backup rechecks and downloads the complete site. Each site is packaged and verified separately. A backup is not reported as successful until its files and package pass verification."),
            ("Pause, close, and resume",
             "PAUSE stops safely between files; the current file is allowed to finish. Choose RESUME to continue.\n\n"
             "While a backup is running, the red X minimizes SUYB and the backup continues. Keep SUYB available from the taskbar; the Windows notification icon is only an optional convenience.\n\n"
             "If Windows restarts or the computer crashes, open SUYB again and choose RESUME when offered. Already downloaded and verified files are not downloaded again. START FRESH deliberately discards that run's saved progress."),
            ("Restoring a site",
             "Open Restore, choose a verified SUYB .zip package, then choose REVIEW RESTORE. SUYB shows what will happen before anything is uploaded. Restoring writes to the selected site, so check the site name and URL before confirming."),
            ("Finding your backups",
             "Open Backups to see packages in the selected site's working folder. Open backup folder shows the same files in Windows so you can copy or manage them there. Refresh rereads the folder."),
            ("Connections",
             "The Connection page stores the site name, URL, backup key, and local working folder. Test connection confirms that the selected site can provide a database export. Save connection after making changes."),
            ("What a complete backup contains",
             "A complete package contains the full database SQL, schema SQL, recovery manifest, original media and site assets. Downloads are checked against the site's manifest before the package is marked successful."),
        ]
        dialog = QDialog(self); dialog.setWindowTitle(f"SMACK UP YOUR BACKUP — Help · {BUILD_VERSION}")
        dialog.resize(850, 570)
        shell = QHBoxLayout(dialog)
        topic_list = QListWidget(); topic_list.setFixedWidth(235)
        body = QTextEdit(); body.setReadOnly(True)
        for title, _text in topics: topic_list.addItem(title)
        def show_topic(row):
            title, text = topics[max(0, row)]
            body.setHtml(f"<h2>{title}</h2><p>{text.replace(chr(10) + chr(10), '</p><p>').replace(chr(10), '<br>')}</p>")
        topic_list.currentRowChanged.connect(show_topic)
        shell.addWidget(topic_list); shell.addWidget(body, 1)
        current_topic = {0: 0, 1: 2, 2: 3, 3: 4}.get(self.pages.currentIndex(), 0)
        topic_list.setCurrentRow(current_topic)
        dialog.exec()

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
        self.selected_profile_names = [name] if name else []
        p = self.current_profile or {}
        self.name_edit.setText(str(p.get("name", ""))); self.url_edit.setText(str(p.get("site_url", "")))
        self.key_edit.setText(str(p.get("api_key", ""))); self.dir_edit.setText(str(p.get("backup_dir", "")))
        shown = str(p.get("site_url", "")).replace("https://", "").rstrip("/")
        self.site_summary.setText(f"{p.get('name', 'No site')}\n{shown or 'No URL'}\nLast successful run: {p.get('last_backup_date') or 'Not yet recorded'}")
        self.connection.setText("● Ready to verify" if p else "Choose a site")
        self.connection.setObjectName("StatusGood" if p else "StatusWarn"); self.connection.style().unpolish(self.connection); self.connection.style().polish(self.connection)
        self.run_btn.setEnabled(bool(p)); self._update_backup_selection(); self._refresh_backups()

    def _choose_sites(self):
        names = profile_manager.list_profiles()
        if not names:
            QMessageBox.information(self, "No sites yet", "Add a site connection first.")
            return
        dialog = QDialog(self); dialog.setWindowTitle("Choose sites to back up")
        dialog.resize(470, 520)
        layout = QVBoxLayout(dialog)
        layout.addWidget(_label("Select one or more sites. Each backup runs and verifies separately.", "Muted"))
        site_list = QListWidget(); site_list.setSelectionMode(QAbstractItemView.MultiSelection)
        site_list.addItems(names); layout.addWidget(site_list, 1)
        chosen = set(self.selected_profile_names or ([self.profile_combo.currentText()] if self.profile_combo.currentText() else []))
        for index in range(site_list.count()):
            site_list.item(index).setSelected(site_list.item(index).text() in chosen)
        buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
        buttons.accepted.connect(dialog.accept); buttons.rejected.connect(dialog.reject); layout.addWidget(buttons)
        if dialog.exec() != QDialog.Accepted:
            return
        selected = [item.text() for item in site_list.selectedItems()]
        if not selected:
            QMessageBox.information(self, "Choose a site", "Select at least one site to back up.")
            return
        self.selected_profile_names = selected
        self._update_backup_selection()

    def _update_backup_selection(self):
        names = list(self.selected_profile_names)
        if len(names) <= 1:
            self.choose_sites_btn.setText("Choose sites…")
            self.run_btn.setText("BACK UP THIS SITE")
            return
        self.choose_sites_btn.setText(f"{len(names)} sites selected")
        self.run_btn.setText(f"BACK UP {len(names)} SITES")
        summaries = []
        for name in names:
            profile = profile_manager.load_profile(name) or {}
            shown = str(profile.get("site_url", "")).replace("https://", "").rstrip("/")
            summaries.append(f"{name}  ·  {shown or 'No URL'}")
        self.site_summary.setText("Selected sites\n" + "\n".join(summaries))

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
        names = list(self.selected_profile_names or [self.current_profile["name"]])
        resume_points = {}
        for name in names:
            profile = profile_manager.load_profile(name) or {}
            cp = BackupCheckpoint.load(
                profile.get("backup_dir", ""), profile.get("name", name))
            if not cp:
                continue
            box = QMessageBox(self)
            box.setWindowTitle("Interrupted backup found")
            box.setIcon(QMessageBox.Information)
            box.setText(f"Resume the interrupted backup for {name}?")
            box.setInformativeText(
                f"It began {cp.data.get('created_at', 'at an unknown time')[:16].replace('T', ' ')}.\n"
                f"{cp.data.get('files_downloaded', 0):,} files were already downloaded and verified; "
                f"{cp.data.get('files_skipped', 0):,} unchanged files were recorded."
            )
            resume_button = box.addButton("RESUME", QMessageBox.AcceptRole)
            fresh_button = box.addButton("START FRESH", QMessageBox.DestructiveRole)
            box.addButton(QMessageBox.Cancel)
            box.setDefaultButton(resume_button)
            box.exec()
            chosen = box.clickedButton()
            if chosen is resume_button:
                resume_points[name] = cp
            elif chosen is fresh_button:
                cp.delete()
            else:
                return
        force_full = self.full_check.isChecked()
        global_cloud = self._global_cloud()
        self.log.clear(); self.run_btn.setEnabled(False); self.choose_sites_btn.setEnabled(False)
        self.pause_btn.setEnabled(True); self.pause_btn.setText("PAUSE")
        if self.tray_pause_action:
            self.tray_pause_action.setEnabled(True)
            self.tray_pause_action.setText("Pause backup")
        if self.tray:
            self.tray.setToolTip("SMACK UP YOUR BACKUP — backup running")
        self._pause_requested = False
        self.run_btn.setText("BACKUP IN PROGRESS…")
        self._backup_started = time.monotonic(); self._site_started = self._backup_started
        self._stats_site = ""; self._last_pct = 0.0; self._last_stats = None
        self.files_stats.setText("FILES\nReading inventory…"); self.data_stats.setText("DATA\nCalculating total…")
        self.time_stats.setText("TIME\nElapsed 0:00 · ETA calculating…"); self._clock.start()
        threading.Thread(target=self._run_backup_batch, args=(names, force_full, global_cloud, resume_points), daemon=True).start()

    def _run_backup_batch(self, names, force_full, global_cloud, resume_points):
        results = []
        total = len(names)
        for index, name in enumerate(names):
            profile = profile_manager.load_profile(name)
            if not profile:
                results.append({"name": name, "success": False, "errors": ["Profile could not be loaded"]})
                continue
            profile = dict(profile); profile["api_key"] = self._resolved_key(profile)
            self.bridge.log.emit(f"{name}: starting backup")
            self.engine = backup_engine.BackupEngine(
                profile, force_full=force_full, global_cloud=global_cloud,
                on_progress=lambda stage, msg, pct, i=index, n=total, site=name:
                    self.bridge.progress.emit(stage, f"{site}: {msg}", (i + float(pct)) / n),
                on_stats=lambda fd, ft, ff, bd, bt, bf, site=name:
                    self.bridge.stats.emit(site, fd, ft, ff, bd, bt, bf),
                on_log=lambda msg, site=name: self.bridge.log.emit(f"{site}: {msg}"),
                resume_checkpoint=resume_points.get(name))
            if self._pause_requested:
                self.engine.pause()
            result = self.engine.run() or {}
            results.append({"name": name, **result})
            if not result.get("success"):
                self.bridge.log.emit(f"{name}: backup needs attention; continuing with the remaining sites")
        self.bridge.finished.emit({
            "success": bool(results) and all(item.get("success") for item in results),
            "profiles": results,
            "errors": [f"{item['name']}: {error}" for item in results for error in item.get("errors", [])],
        })

    def _on_progress(self, _stage, message, pct):
        self._last_pct = max(0.0, min(1.0, float(pct)))
        self.progress.setValue(int(self._last_pct * 100)); self.progress_text.setText(message)
        if self.tray:
            self.tray.setToolTip(
                f"SMACK UP YOUR BACKUP — {int(self._last_pct * 100)}% — {message[:80]}")

    @staticmethod
    def _fmt_bytes(value):
        value = float(value or 0)
        for unit in ("B", "KB", "MB", "GB", "TB"):
            if value < 1024 or unit == "TB": return f"{value:.0f} {unit}" if unit in ("B", "KB") else f"{value:.1f} {unit}"
            value /= 1024

    @staticmethod
    def _fmt_time(seconds):
        seconds = max(0, int(seconds or 0)); hours, rem = divmod(seconds, 3600); mins, secs = divmod(rem, 60)
        return f"{hours}:{mins:02d}:{secs:02d}" if hours else f"{mins}:{secs:02d}"

    def _on_stats(self, site, files_done, files_total, files_failed, bytes_done, bytes_total, bytes_failed):
        if site != self._stats_site:
            self._stats_site = site; self._site_started = time.monotonic()
        self._last_stats = (site, files_done, files_total, files_failed, bytes_done, bytes_total, bytes_failed)
        remaining_files = max(0, files_total - files_done)
        self.files_stats.setText(f"FILES · {site}\n{files_done:,} complete · {remaining_files:,} remaining · {files_total:,} total" + (f" · {files_failed:,} failed" if files_failed else ""))
        remaining_bytes = max(0, bytes_total - bytes_done)
        self.data_stats.setText(f"DATA CHECKED\n{self._fmt_bytes(bytes_done)} complete · {self._fmt_bytes(remaining_bytes)} remaining · {self._fmt_bytes(bytes_total)} total")
        self._refresh_stats()

    def _refresh_stats(self):
        if self._backup_started is None: return
        elapsed = time.monotonic() - self._backup_started
        eta = (elapsed / self._last_pct * (1.0 - self._last_pct)) if self._last_pct > .01 else None
        detail = f"Elapsed {self._fmt_time(elapsed)}"
        detail += f" · ETA {self._fmt_time(eta)}" if eta is not None else " · ETA calculating…"
        if self._last_stats and self._site_started:
            _site, _fd, _ft, _ff, bd, _bt, _bf = self._last_stats
            rate = bd / max(1.0, time.monotonic() - self._site_started)
            if rate > 0: detail += f" · {self._fmt_bytes(rate)}/s checked"
        self.time_stats.setText("TIME\n" + detail)

    def _on_log(self, message):
        self.log.append(message)

    def _toggle_pause(self):
        if not self.engine:
            return
        if self._pause_requested:
            self._pause_requested = False
            self.engine.resume()
            self.pause_btn.setText("PAUSE")
            if self.tray_pause_action:
                self.tray_pause_action.setText("Pause backup")
            self.progress_text.setText("Backup resumed.")
            self._clock.start()
            self._on_log("Backup resumed.")
        else:
            self._pause_requested = True
            self.engine.pause()
            self.pause_btn.setText("RESUME")
            if self.tray_pause_action:
                self.tray_pause_action.setText("Resume backup")
            self.progress_text.setText("Pausing safely after the current file…")
            self._clock.stop()
            self._on_log("Pause requested — the current file will finish safely, then backup will wait.")

    def _backup_done(self, result):
        self._clock.stop()
        self.engine = None; self._pause_requested = False
        self.pause_btn.setEnabled(False); self.pause_btn.setText("PAUSE")
        if self.tray_pause_action:
            self.tray_pause_action.setEnabled(False)
            self.tray_pause_action.setText("Pause backup")
        if self.tray:
            self.tray.setToolTip(
                "SMACK UP YOUR BACKUP — backup complete" if ok
                else "SMACK UP YOUR BACKUP — backup needs attention")
        self.run_btn.setEnabled(True); self.choose_sites_btn.setEnabled(True)
        ok = bool((result or {}).get("success")); self.progress.setValue(100 if ok else self.progress.value())
        self.progress_text.setText("Backup completed and verified." if ok else "Backup needs attention. Details are above.")
        self._refresh_stats()
        for item in (result or {}).get("profiles", []):
            if item.get("success"):
                profile = profile_manager.load_profile(item.get("name"))
                if profile:
                    profile["last_backup_date"] = datetime.now().strftime("%Y-%m-%d %H:%M")
                    profile_manager.save_profile(profile)
        if self.current_profile:
            refreshed = profile_manager.load_profile(self.current_profile["name"])
            if refreshed: self.current_profile = refreshed
        self._update_backup_selection()
        if not ok:
            errors = "\n".join((result or {}).get("errors", [])) or "The backup did not complete."
            if self.isVisible():
                QMessageBox.warning(self, "Backup needs attention", errors[:1800])
            self._tray_message("Backup needs attention", errors[:500], critical=True)
        else:
            self._tray_message("Backup complete", "The backup completed and verified successfully.")

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

    def _open_backup_folder(self):
        folder = os.path.abspath(str((self.current_profile or {}).get("backup_dir", "")))
        if not os.path.isdir(folder):
            QMessageBox.information(self, "No backup folder yet", "Choose an existing working folder in Connection first.")
            return
        if not QDesktopServices.openUrl(QUrl.fromLocalFile(folder)):
            QMessageBox.warning(self, "Could not open folder", f"The backup folder could not be opened:\n{folder}")

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
            event.ignore()
            self.showMinimized()
            self._tray_message(
                "Backup still running",
                "SMACK UP YOUR BACKUP was minimized and remains available on the taskbar.")
            return
        if self.tray:
            self.tray.hide()
        event.accept()
        QApplication.instance().quit()


def run():
    app = QApplication.instance() or QApplication(sys.argv)
    app.setQuitOnLastWindowClosed(False)
    app.setApplicationName("SMACK UP YOUR BACKUP")
    app.setWindowIcon(QIcon(_icon_path()))
    app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    font = QFont("Segoe UI", 10); app.setFont(font)
    window = SuybWindow(); window.show()
    return app.exec()

# ===== SNAPSMACK EOF =====
