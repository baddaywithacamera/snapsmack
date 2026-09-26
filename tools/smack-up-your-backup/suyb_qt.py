"""SMACK UP YOUR BACKUP — modern Qt desktop shell.

The backup/restore/cloud engines remain the authority. This module owns only
presentation, user intent, progress, and plain-language error reporting.

The window, the Overview / Restore / Backups / Connection pages live here.
Every other page lives in its own suyb_qt_<page>.py and follows the page
contract written at the top of suyb_qt_common.py.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import threading
import time
from datetime import datetime

from PySide6.QtCore import QObject, Qt, Signal, QTimer, QUrl, QSettings
from PySide6.QtGui import QAction, QDesktopServices, QIcon, QFont, QKeySequence
from PySide6.QtWidgets import (
    QAbstractItemView, QApplication, QButtonGroup, QCheckBox, QComboBox,
    QDialog, QDialogButtonBox, QFileDialog, QFrame, QHBoxLayout, QInputDialog,
    QLineEdit, QListWidget, QMainWindow, QMenu, QMessageBox, QProgressBar,
    QPushButton, QRadioButton, QScrollArea, QSizePolicy, QStackedWidget, QTextEdit,
    QSystemTrayIcon, QVBoxLayout, QWidget,
)

import backup_engine
from checkpoint import BackupCheckpoint
import cloud_client
import config as config_module
import ftps_pins
import profile_manager
import restore_engine
import secret_vault
from _version import BUILD_VERSION
from suyb_qt_common import (
    STYLE, FitScrollArea, Relay, card as _card, engine_asker, label as _label,
)
from suyb_qt_audit import AuditPage
from suyb_qt_cloudsync import CloudSyncPage
from suyb_qt_manage import CloudBrowserDialog, ManagePage
from suyb_qt_profiles import ProfileDialog, SetupWizard, delete_profile_confirmed
from suyb_qt_schedule import InAppScheduler, SchedulePage
from suyb_qt_settings import SettingsPage, global_cloud_from_config
from suyb_qt_slaphappy import SlapHappyPage


def _icon_path():
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, "assets", "suyb.ico")


class Bridge(QObject):
    progress = Signal(str, str, float)
    # The last three are BYTE counts. PySide6 maps a bare `int` to C++ `int`,
    # which is 32-bit: a site over 2,147,483,647 bytes (2.1 GB) wrapped to a
    # NEGATIVE number and the cockpit read "-268704206 B complete" on a 12 GB
    # backup (Sean, forever photographing, 2026-09-23). qint64 carries the real
    # value. Counts stay `int` — 2.1 billion files is not a thing.
    stats = Signal(str, int, int, int, 'qint64', 'qint64', 'qint64')
    log = Signal(str)
    finished = Signal(object)
    tested = Signal(bool, str)
    restoreProgress = Signal(str, float)
    restoreLog = Signal(str)
    restoreFinished = Signal(object)


class SuybWindow(QMainWindow):
    # Page contract (suyb_qt_common): pages listen for the selected site here.
    profileChanged = Signal(object)

    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"SMACK UP YOUR BACKUP — {BUILD_VERSION}")
        self.setWindowIcon(QIcon(_icon_path()))
        self.resize(1280, 840)
        self.setMinimumSize(1020, 680)
        self._window_settings = QSettings("SnapSmack", "SMACK UP YOUR BACKUP")
        geometry = self._window_settings.value("window/normal_geometry")
        if geometry:
            self.restoreGeometry(geometry)
        self._restore_maximized = self._window_settings.value("window/maximized", False, type=bool)
        self.relay = Relay(self)
        self.bridge = Bridge()
        self.bridge.progress.connect(self._on_progress)
        self.bridge.stats.connect(self._on_stats)
        self.bridge.log.connect(self._on_log)
        self.bridge.finished.connect(self._backup_done)
        self.bridge.tested.connect(self._test_done)
        self.bridge.restoreProgress.connect(self._on_restore_progress)
        self.bridge.restoreLog.connect(self._on_restore_log)
        self.bridge.restoreFinished.connect(self._restore_done)
        self.engine = None
        self._restoring = False
        self._pause_requested = False
        self._cancel_requested = False
        self._unattended = False
        self._backup_started = None
        self._site_started = None
        self._stats_site = ""
        self._last_pct = 0.0
        self._last_stats = None
        self._clock = QTimer(self); self._clock.setInterval(1000); self._clock.timeout.connect(self._refresh_stats)
        self.current_profile = None
        self.selected_profile_names = []
        self._cloud_file_id = ""
        self._quitting = False
        self._build()
        self._build_tray()
        self._load_profiles()
        help_action = QAction("Help", self)
        help_action.setShortcut(QKeySequence.HelpContents)
        help_action.triggered.connect(self._show_help)
        self.addAction(help_action)
        self.scheduler = InAppScheduler(self)
        self.scheduler.start(self)

    # ── Page contract (suyb_qt_common) ──────────────────────────────────

    def global_cloud(self):
        return global_cloud_from_config()

    def reload_profiles(self, name=""):
        self._load_profiles(select=name)

    def busy(self):
        return bool(self.engine)

    def notify(self, title, message, critical=False):
        self._tray_message(title, message, critical)

    def run_backup_for(self, names, force_full=False, unattended=False):
        names = [n for n in (names or []) if n]
        if not names:
            return False
        if self.engine:
            if not unattended:
                QMessageBox.information(
                    self, "A job is already running",
                    "A backup or restore is already running. Wait for it to finish, then try again.")
            return False
        self.selected_profile_names = names
        if len(names) == 1 and self.profile_combo.findText(names[0]) >= 0:
            self.profile_combo.setCurrentText(names[0])
            self.selected_profile_names = names
        self._update_backup_selection()
        self._show_page(0)
        return self._start_backup(names, force_full=force_full, unattended=unattended)

    # ── Tray ─────────────────────────────────────────────────────────────

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
        backup_action = QAction("Back up now", self)
        backup_action.triggered.connect(lambda: (self._show_window(), self._run_backup()))
        menu.addAction(backup_action)
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
        self._quit()

    def _quit(self):
        self._quitting = True
        if self.engine:
            self._cancel_requested = True
            self.engine.cancel()
        self._save_window_state()
        try:
            self.scheduler.stop()
        except Exception:
            pass
        for page in self._page_widgets:
            if hasattr(page, "shutdown"):
                try:
                    page.shutdown()
                except Exception:
                    pass
        if self.tray:
            self.tray.hide()
        QApplication.instance().quit()

    # ── Layout ───────────────────────────────────────────────────────────

    def _build(self):
        root = QWidget(); shell = QHBoxLayout(root); shell.setContentsMargins(0, 0, 0, 0); shell.setSpacing(0)
        side = QFrame(); side.setObjectName("Sidebar"); side.setFixedWidth(232)
        sl = QVBoxLayout(side); sl.setContentsMargins(18, 24, 18, 18); sl.setSpacing(4)
        sl.addWidget(_label("SNAPSMACK", "Eyebrow"))
        sl.addWidget(_label("SMACK UP\nYOUR BACKUP", "Brand")); sl.addSpacing(18)
        self.nav_group = QButtonGroup(self); self.nav_group.setExclusive(True)
        self.pages = QStackedWidget()
        self.manage_page = ManagePage(self)
        entries = (("Overview", "Your safety net", self._overview_page()),
                   ("Restore", "Bring a site back", self._restore_page()),
                   ("Backups", "What you have", self._backups_page()),
                   ("Manage", "Backups in the cloud", self.manage_page),
                   ("Audit", "Check what you have", AuditPage(self)),
                   ("Schedule", "When backups run", SchedulePage(self)),
                   ("Cloud Sync", "Copy between clouds", CloudSyncPage(self)),
                   ("SLAP HAPPY", "Back up SNAP SLAPPER", SlapHappyPage(self)),
                   ("Connection", "Site and destination", self._settings_page()),
                   ("Settings", "Transfer, cloud, security", SettingsPage(self)))
        self._page_widgets = []
        self._nav_buttons = []
        nav_host = QWidget(); nav = QVBoxLayout(nav_host); nav.setContentsMargins(0, 0, 0, 0); nav.setSpacing(2)
        for index, (name, tip, widget) in enumerate(entries):
            button = QPushButton(f"{name}\n{tip}"); button.setObjectName("Nav"); button.setCheckable(True)
            button.clicked.connect(lambda _=False, i=index: self._show_page(i))
            self.nav_group.addButton(button); nav.addWidget(button); self._nav_buttons.append(button)
            self.pages.addWidget(widget); self._page_widgets.append(widget)
            if index == 0: button.setChecked(True)
        nav.addStretch(1)
        # Ten pages outgrow a short window; the list scrolls instead of clipping.
        nav_scroll = QScrollArea(); nav_scroll.setWidgetResizable(True)
        nav_scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff); nav_scroll.setWidget(nav_host)
        sl.addWidget(nav_scroll, 1)
        sl.addWidget(_label(f"BUILD {BUILD_VERSION}\nLocal-first · positively verified", "Muted"))
        shell.addWidget(side)
        # Manage hands a chosen backup to Restore: a downloaded local zip (Backblaze)
        # or a cloud file id + name (Drive / Box), as the Tk select_cloud_backup did.
        self.manage_page.restoreRequested.connect(self._restore_requested)
        self.manage_page.cloudRestoreRequested.connect(self._restore_requested)

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
        run, rl = _card("Make a fresh backup")
        # Full vs differential is a two-way choice, so BOTH ways are named and on
        # screen. It used to be a single "Full backup" tick-box: ticked told you
        # what you were getting, cleared told you nothing at all, so there was no
        # way to know that clearing it meant a differential run. The card's
        # subtitle carried that explanation and the control contradicted it
        # (Sean, 2026-09-23: "the display is a bit confusing, like picking full
        # backup"). Differential stays the default, exactly as the cleared box was.
        # 0.7.48: two big buttons, the chosen one lit green. Radio buttons in this
        # theme showed a tiny dot on the chosen one and NOTHING on the other, so
        # it was hard to see which was picked or that there was a choice at all
        # (Sean, 2026-09-26: "you made it harder").
        opts = QHBoxLayout(); opts.setSpacing(10)
        opts.addWidget(_label("BACKUP TYPE", "Eyebrow"))
        self.mode_group = QButtonGroup(self); self.mode_group.setExclusive(True)
        self.mode_diff = QPushButton("DIFFERENTIAL" + chr(10) + "Only files that changed")
        self.mode_full = QPushButton("FULL" + chr(10) + "Recheck every file on the site")
        for _b in (self.mode_diff, self.mode_full):
            _b.setObjectName("Choice"); _b.setCheckable(True)
            self.mode_group.addButton(_b); opts.addWidget(_b)
        self.mode_diff.setChecked(True)
        opts.addStretch(1)
        rl.addLayout(opts)
        extras = QHBoxLayout()
        # Exit package: TYSWY canonical archive + WordPress + Ghost, written into exit/
        # and zipped with the backup. Off by default — it can double a backup's size.
        self.exit_check = QCheckBox("Include exit package (WordPress + Ghost; larger)")
        self.exit_check.setToolTip(
            "Also writes TAKE YOUR SHIT WITH YOU's portable archive plus WordPress and Ghost import "
            "packages into exit/ inside this backup. Every original is included once more, so the "
            "backup can be roughly twice the size. Needs the site on 0.7.711D or newer.")
        extras.addWidget(self.exit_check)
        # Restored from the Tk window (dropped in the Qt rebuild): the site's SUYB
        # settings travel inside the package. On by default, as it was.
        self.settings_check = QCheckBox("Include SUYB settings")
        self.settings_check.setChecked(True)
        self.settings_check.setToolTip(
            "Adds suyb-settings.json to the package: this site's SUYB profile and the global "
            "settings, so a new computer can be set up from the backup. Passwords and keys "
            "are never included.")
        extras.addWidget(self.settings_check); extras.addStretch(1)
        rl.addLayout(extras)
        # The three actions get their own row: beside the options they were
        # squeezed to nothing at the smallest window size.
        extras = QHBoxLayout(); extras.addStretch(1)
        self.cancel_btn = QPushButton("CANCEL"); self.cancel_btn.setObjectName("Danger")
        self.cancel_btn.setEnabled(False); self.cancel_btn.clicked.connect(self._cancel_backup)
        extras.addWidget(self.cancel_btn)
        self.pause_btn = QPushButton("PAUSE")
        self.pause_btn.setEnabled(False)
        self.pause_btn.clicked.connect(self._toggle_pause)
        extras.addWidget(self.pause_btn)
        self.run_btn = QPushButton("BACK UP THIS SITE"); self.run_btn.setObjectName("Primary"); self.run_btn.clicked.connect(self._run_backup); extras.addWidget(self.run_btn)
        rl.addLayout(extras); self.progress = QProgressBar(); self.progress.setRange(0, 100); rl.addWidget(self.progress)
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
        card, col = _card("Backup package", "Where the backup comes from.")
        self.restore_source = QButtonGroup(self); self.restore_source.setExclusive(True)
        self.src_local = QRadioButton("Local backup package (.zip)")
        self.src_cloud = QRadioButton("Browse cloud")
        self.src_manual = QRadioButton("Recovery kit + media folder")
        self.src_local.setChecked(True)
        for button in (self.src_local, self.src_cloud, self.src_manual):
            self.restore_source.addButton(button); col.addWidget(button)
            button.toggled.connect(self._restore_source_changed)

        self.local_row = QWidget(); row = QHBoxLayout(self.local_row); row.setContentsMargins(0, 0, 0, 0)
        self.restore_path = QLineEdit(); self.restore_path.setPlaceholderText("Choose a local backup package…")
        choose = QPushButton("Choose file…"); choose.clicked.connect(self._choose_restore)
        row.addWidget(self.restore_path, 1); row.addWidget(choose); col.addWidget(self.local_row)

        self.cloud_row = QWidget(); crow = QHBoxLayout(self.cloud_row); crow.setContentsMargins(0, 0, 0, 0)
        browse_cloud = QPushButton("Browse cloud…"); browse_cloud.clicked.connect(self._browse_cloud)
        self.cloud_choice = _label("No package selected", "Muted")
        crow.addWidget(browse_cloud); crow.addWidget(self.cloud_choice, 1); col.addWidget(self.cloud_row)

        self.manual_row = QWidget(); mcol = QVBoxLayout(self.manual_row); mcol.setContentsMargins(0, 0, 0, 0)
        self.kit_path = QLineEdit(); self.kit_path.setPlaceholderText("Recovery kit (.tar.gz)")
        self.media_path = QLineEdit(); self.media_path.setPlaceholderText("Media folder")
        for edit, handler in ((self.kit_path, self._choose_kit), (self.media_path, self._choose_media)):
            line = QHBoxLayout(); line.addWidget(edit, 1)
            pick = QPushButton("Choose…"); pick.clicked.connect(handler); line.addWidget(pick)
            mcol.addLayout(line)
        col.addWidget(self.manual_row)

        buttons = QHBoxLayout(); buttons.addStretch(1)
        self.restore_cancel_btn = QPushButton("CANCEL"); self.restore_cancel_btn.setObjectName("Danger")
        self.restore_cancel_btn.setEnabled(False); self.restore_cancel_btn.clicked.connect(self._cancel_restore)
        buttons.addWidget(self.restore_cancel_btn)
        self.restore_btn = QPushButton("REVIEW RESTORE"); self.restore_btn.setObjectName("Primary"); self.restore_btn.clicked.connect(self._run_restore)
        buttons.addWidget(self.restore_btn); col.addLayout(buttons)
        layout.addWidget(card)

        progress, pl = _card("Restore progress")
        self.restore_progress = QProgressBar(); self.restore_progress.setRange(0, 100); pl.addWidget(self.restore_progress)
        self.restore_text = _label("Nothing restored yet.", "Muted"); pl.addWidget(self.restore_text)
        self.restore_log = QTextEdit(); self.restore_log.setReadOnly(True); self.restore_log.setMinimumHeight(120)
        self.restore_log.setPlaceholderText("Restore activity will appear here.")
        pl.addWidget(self.restore_log, 1); layout.addWidget(progress, 1)
        self._restore_source_changed()
        return page

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
        forget_cert = QPushButton("Forget FTPS certificate")
        forget_cert.clicked.connect(self._forget_certificate); buttons.addWidget(forget_cert)
        save = QPushButton("Save connection"); save.setObjectName("Primary"); save.clicked.connect(self._save_profile); buttons.addWidget(save); col.addLayout(buttons)
        layout.addWidget(card)
        # Restored from the Tk header (+ New / Edit / Dup / Del).
        sites, sc = _card("Sites", "Add, edit every field of, copy, or remove a site. Removing a site never touches its backups.")
        row = QHBoxLayout()
        for text, handler, name in (("+ Add site", self._new_profile, ""),
                                    ("Edit all details…", self._edit_profile, ""),
                                    ("Duplicate", self._dup_profile, ""),
                                    ("Delete site…", self._del_profile, "Danger")):
            button = QPushButton(text); button.clicked.connect(handler)
            if name: button.setObjectName(name)
            row.addWidget(button)
        row.addStretch(1); sc.addLayout(row)
        layout.addWidget(sites); layout.addStretch(1); return page

    def _show_page(self, index):
        self.pages.setCurrentIndex(index)
        if 0 <= index < len(self._nav_buttons):
            self._nav_buttons[index].setChecked(True)
        if index == 2: self._refresh_backups()
        widget = self._page_widgets[index] if 0 <= index < len(self._page_widgets) else None
        if widget is not None and hasattr(widget, "refresh"):
            widget.refresh()

    # ── Help ─────────────────────────────────────────────────────────────

    def _show_help(self):
        topics = [
            ("Making a backup",
             "Choose one site above, or use Choose sites… to select several (Select all backs up every site).\n\n"
             "Differential downloads only files that are new or changed. Full backup rechecks and downloads the complete site. Each site is packaged and verified separately. A backup is not reported as successful until its files and package pass verification.\n\n"
             "Include SUYB settings adds this site's SUYB profile and the global settings to the package, so a new computer can be set up from it. Passwords and keys are never included.\n\n"
             "If too many files fail to download, SUYB stops and asks whether to abort or continue. Scheduled backups never ask: they abort."),
            ("Pause, cancel, close, and resume",
             "PAUSE stops safely between files; the current file is allowed to finish. Choose RESUME to continue. CANCEL stops the run and any sites still waiting in the queue.\n\n"
             "While a backup is running, the red X minimizes SUYB and the backup continues. Keep SUYB available from the taskbar; the Windows notification icon is only an optional convenience.\n\n"
             "If Windows restarts or the computer crashes, open SUYB again and choose RESUME when offered. Already downloaded and verified files are not downloaded again. START FRESH deliberately discards that run's saved progress."),
            ("Restoring a site",
             "Open Restore and choose where the backup comes from: a local SUYB .zip package, a package in your backup cloud (Browse cloud…), or a recovery kit (.tar.gz) plus its media folder. Then choose REVIEW RESTORE and confirm. Restoring writes to the selected site, so check the site name and URL before confirming. CANCEL stops a restore in progress."),
            ("Finding your backups",
             "Open Backups to see packages in the selected site's working folder. Open backup folder shows the same files in Windows so you can copy or manage them there. Refresh rereads the folder. Manage shows the backups in your cloud."),
            ("Connections and sites",
             "The Connection page stores the site name, URL, backup key, and local working folder. Test connection confirms that the selected site can provide a database export. Save connection after making changes.\n\n"
             "+ Add site sets up a new site. Edit all details… opens every setting for the selected site. Duplicate copies it. Delete site… removes only SUYB's settings for that site on this computer; backups on disk or in the cloud are not touched."),
            ("The exit package (WordPress + Ghost)",
             "Tick Include exit package and each backup also carries an exit/ folder: TAKE YOUR SHIT WITH YOU's portable archive "
             "(plain JSON and your media, hash-verified) plus a WordPress import file and a Ghost import zip built from it. "
             "It is off by default because every original is included once more, so the backup can be about twice the size.\n\n"
             "Each package lists, in its own CONVERSION-REPORT.html, everything that platform cannot hold: WordPress turns albums "
             "into tags and skips collections; Ghost does the same and cannot import comments at all. Nothing is dropped silently, "
             "and the portable archive beside them keeps all of it.\n\n"
             "The site must be on 0.7.711D or newer for the backup key to read the export; older sites need TAKE YOUR SHIT WITH YOU "
             "with its own key. A problem building the exit package never fails the backup itself."),
            ("What a complete backup contains",
             "A complete package contains the full database SQL, schema SQL, recovery manifest, original media and site assets. Downloads are checked against the site's manifest before the package is marked successful."),
        ]
        first_topic = {0: 0, 1: 2, 2: 3, 8: 4}
        for index, page in enumerate(self._page_widgets):
            if hasattr(page, "help_topics"):
                try:
                    extra = list(page.help_topics() or [])
                except Exception:
                    extra = []
                if extra:
                    first_topic.setdefault(index, len(topics))
                    topics.extend(extra)
        dialog = QDialog(self); dialog.setWindowTitle(f"SMACK UP YOUR BACKUP — Help · {BUILD_VERSION}")
        dialog.resize(900, 600)
        shell = QHBoxLayout(dialog)
        topic_list = QListWidget(); topic_list.setFixedWidth(260)
        body = QTextEdit(); body.setReadOnly(True)
        for title, _text in topics: topic_list.addItem(title)
        def show_topic(row):
            title, text = topics[max(0, row)]
            body.setHtml(f"<h2>{title}</h2><p>{text.replace(chr(10) + chr(10), '</p><p>').replace(chr(10), '<br>')}</p>")
        topic_list.currentRowChanged.connect(show_topic)
        shell.addWidget(topic_list); shell.addWidget(body, 1)
        topic_list.setCurrentRow(first_topic.get(self.pages.currentIndex(), 0))
        dialog.exec()

    # ── Sites ────────────────────────────────────────────────────────────

    def _load_profiles(self, select=""):
        self.profile_combo.blockSignals(True); self.profile_combo.clear()
        self.profile_combo.addItems(profile_manager.list_profiles())
        cfg = config_module.load(); wanted = select or cfg.get("app", "last_profile", fallback="")
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
        self._render_site_summary()
        self.connection.setText("● Ready to verify" if p else "Choose a site")
        self.connection.setObjectName("StatusGood" if p else "StatusWarn"); self.connection.style().unpolish(self.connection); self.connection.style().polish(self.connection)
        self.run_btn.setEnabled(bool(p) and not self.engine); self._update_backup_selection(); self._refresh_backups()
        self._cloud_file_id = ""; self.cloud_choice.setText("No package selected")
        if name:
            self._remember_last_profile(name)
        self.profileChanged.emit(self.current_profile)

    @staticmethod
    def _remember_last_profile(name):
        """The Tk window wrote this on close; the Qt window read it but never wrote it."""
        try:
            cfg = config_module.load()
            if not cfg.has_section("app"):
                cfg.add_section("app")
            if cfg.get("app", "last_profile", fallback="") != name:
                cfg.set("app", "last_profile", name)
                config_module.save(cfg)
        except Exception:
            pass

    def _new_profile(self):
        dialog = ProfileDialog(self, title="New site")
        if dialog.exec() == QDialog.Accepted and dialog.result:
            profile_manager.save_profile(dialog.result)
            self._load_profiles(select=dialog.result["name"])

    def _edit_profile(self):
        if not self.current_profile:
            QMessageBox.information(self, "No site", "Select a site first.")
            return
        dialog = ProfileDialog(self, profile=self.current_profile, title="Edit site")
        if dialog.exec() == QDialog.Accepted and dialog.result:
            old_name = self.current_profile.get("name", "")
            profile_manager.save_profile(dialog.result)
            if old_name and old_name != dialog.result["name"]:
                profile_manager.delete_profile(old_name)
            self._load_profiles(select=dialog.result["name"])

    def _dup_profile(self):
        if not self.current_profile:
            QMessageBox.information(self, "No site", "Select a site first.")
            return
        name = self.current_profile["name"]
        new_name = f"{name} (copy)"
        profile_manager.duplicate_profile(name, new_name)
        self._load_profiles(select=new_name)

    def _del_profile(self):
        if not self.current_profile:
            QMessageBox.information(self, "No site", "Select a site first.")
            return
        if delete_profile_confirmed(self, self.current_profile["name"]):
            self.current_profile = None
            self._load_profiles()

    def _first_run(self):
        """No sites yet: the setup wizard, as the Tk window did on first launch."""
        if profile_manager.list_profiles():
            return
        wizard = SetupWizard(self)
        if wizard.exec() == QDialog.Accepted and wizard.result:
            profile_manager.save_profile(wizard.result)
            self._load_profiles(select=wizard.result["name"])

    def _choose_sites(self):
        names = profile_manager.list_profiles()
        if not names:
            QMessageBox.information(self, "No sites yet", "Add a site first: Connection → + Add site.")
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
        select_row = QHBoxLayout()
        select_all = QPushButton("Select all"); select_all.clicked.connect(site_list.selectAll); select_row.addWidget(select_all)
        select_none = QPushButton("Select none"); select_none.clicked.connect(site_list.clearSelection); select_row.addWidget(select_none)
        select_row.addStretch(1); layout.addLayout(select_row)
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

    def _render_site_summary(self):
        """Draw the selected-site panel, including its "Last successful run" line.

        Its own method because a finished backup writes a new last_backup_date to
        the profile on disk and reloads self.current_profile, but nothing redrew
        this label — with one site selected _update_backup_selection() returns
        early. The panel kept showing a month-old date under a log that said
        "Backup completed and verified", which reads as a failed backup and had
        Sean asking whether he had to run the whole thing again (2026-09-23).
        """
        p = self.current_profile or {}
        shown = str(p.get("site_url", "")).replace("https://", "").rstrip("/")
        self.site_summary.setText(
            f"{p.get('name', 'No site')}\n{shown or 'No URL'}\n"
            f"Last successful run: {p.get('last_backup_date') or 'Not yet recorded'}")

    def _update_backup_selection(self):
        names = list(self.selected_profile_names)
        if len(names) <= 1:
            self.choose_sites_btn.setText("Choose sites…")
            if not getattr(self, "engine", None):
                self.run_btn.setText("BACK UP THIS SITE")
            self._render_site_summary()
            return
        self.choose_sites_btn.setText(f"{len(names)} sites selected")
        if not getattr(self, "engine", None):
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
        return self.global_cloud()

    @staticmethod
    def _global_config_dict():
        """config.ini as plain dicts, bundled when Include SUYB settings is on (as Tk did)."""
        cfg = config_module.load()
        return {section: dict(cfg[section]) for section in cfg.sections()}

    # ── Backup ───────────────────────────────────────────────────────────

    def _run_backup(self):
        if not self.current_profile or self.engine: return
        names = list(self.selected_profile_names or [self.current_profile["name"]])
        self._start_backup(names, force_full=self.mode_full.isChecked(), unattended=False)

    def _preflight(self, names):
        """The Tk checks before a run: a working folder, and a cloud that can be reached.

        Returns the names to run, or None to stop.
        """
        runnable, missing = [], []
        for name in names:
            profile = profile_manager.load_profile(name) or {}
            (runnable if profile.get("backup_dir") else missing).append(name)
        if missing:
            message = "These sites have no working folder and will be skipped:\n• " + "\n• ".join(missing)
            if not runnable:
                QMessageBox.warning(self, "No working folder", message + "\n\nSet one on the Connection page.")
                return None
            QMessageBox.warning(self, "Skipping sites", message)
        gc = self._global_cloud()
        unconfigured = []
        for name in runnable:
            profile = profile_manager.load_profile(name) or {}
            if profile.get("backup_method") != "cloud":
                continue
            try:
                client = cloud_client.get_cloud_client(profile, global_cloud=gc)
            except Exception:
                client = None
            if not client:
                unconfigured.append(name)
        if unconfigured:
            provider = gc.get("cloud_provider") or "none"
            if provider == "google_drive":
                why = ("The cloud is set to Google Drive but no credentials file is configured. "
                       "Go to Settings → Global cloud, set the credentials JSON and save.")
            else:
                why = "No cloud provider is configured. Go to Settings → Global cloud and set a provider."
            if QMessageBox.question(
                    self, "Cloud not configured",
                    f"{why}\n\nSites affected:\n• " + "\n• ".join(unconfigured)
                    + "\n\nContinue with a local-only backup instead?",
                    QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
                return None
        return runnable

    def _start_backup(self, names, force_full=False, unattended=False):
        resume_points = {}
        if unattended:
            # Tk's scheduled run: differential, settings bundled, never a prompt.
            force_full = False
            include_settings = True
            global_config = None
            exit_package = False
        else:
            names = self._preflight(names)
            if not names:
                return False
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
                    return False
            include_settings = self.settings_check.isChecked()
            global_config = self._global_config_dict() if include_settings else None
            exit_package = self.exit_check.isChecked()
        global_cloud = self._global_cloud()
        self._unattended = unattended
        self._cancel_requested = False
        self.log.clear(); self.run_btn.setEnabled(False); self.choose_sites_btn.setEnabled(False)
        self.pause_btn.setEnabled(True); self.pause_btn.setText("PAUSE"); self.cancel_btn.setEnabled(True)
        if unattended:
            self.log.append(f"Scheduled backup starting: {', '.join(names)}")
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
        # A placeholder so busy() is true from this instant, before the worker
        # thread has built the first real engine.
        self.engine = _StartingEngine()
        options = dict(force_full=force_full, global_cloud=global_cloud, resume_points=resume_points,
                       exit_package=exit_package, include_settings=include_settings,
                       global_config=global_config, unattended=unattended)
        threading.Thread(target=self._run_backup_batch, args=(names, options), daemon=True).start()
        return True

    def _run_backup_batch(self, names, options):
        results = []
        total = len(names)
        asker = None if options["unattended"] else engine_asker(
            self.relay, self, "Download failure", "Abort backup", lambda: self.engine)
        for index, name in enumerate(names):
            if self._cancel_requested:
                self.bridge.log.emit(f"{name}: skipped — the run was cancelled")
                results.append({"name": name, "success": False, "cancelled": True, "errors": []})
                continue
            profile = profile_manager.load_profile(name)
            if not profile:
                results.append({"name": name, "success": False, "errors": ["Profile could not be loaded"]})
                continue
            profile = dict(profile); profile["api_key"] = self._resolved_key(profile)
            profile["exit_package"] = bool(options["exit_package"] or profile.get("exit_package"))
            self.bridge.log.emit(f"{name}: starting backup" + (" (with exit package)" if profile["exit_package"] else ""))
            engine = backup_engine.BackupEngine(
                profile, force_full=options["force_full"], global_cloud=options["global_cloud"],
                include_settings=options["include_settings"], global_config=options["global_config"],
                on_ask=asker,
                on_progress=lambda stage, msg, pct, i=index, n=total, site=name:
                    self.bridge.progress.emit(stage, f"{site}: {msg}", (i + float(pct)) / n),
                on_stats=lambda fd, ft, ff, bd, bt, bf, site=name:
                    self.bridge.stats.emit(site, fd, ft, ff, bd, bt, bf),
                on_log=lambda msg, site=name: self.bridge.log.emit(f"{site}: {msg}"),
                resume_checkpoint=options["resume_points"].get(name))
            self.engine = engine
            if self._cancel_requested:
                engine.cancel()
            if self._pause_requested:
                engine.pause()
            result = engine.run() or {}
            results.append({"name": name, **result})
            if result.get("cancelled"):
                self.bridge.log.emit(
                    f"{name}: cancelled — {result.get('files_downloaded', 0)} downloaded, "
                    f"{result.get('files_skipped', 0)} skipped, {result.get('files_failed', 0)} failed")
            elif not result.get("success"):
                self.bridge.log.emit(f"{name}: backup needs attention; continuing with the remaining sites")
        self.bridge.finished.emit({
            "success": bool(results) and all(item.get("success") for item in results),
            "cancelled": any(item.get("cancelled") for item in results),
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
        if not self.engine or self._restoring:
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

    def _cancel_backup(self):
        """Restored from the Tk window: stop this run and every site still queued."""
        if not self.engine or self._restoring:
            return
        if QMessageBox.question(
                self, "Cancel this backup?",
                "Stop the backup now? Sites still waiting in the queue are skipped. "
                "Files already downloaded and verified are kept, so the next run can resume.",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        self._cancel_requested = True
        self.cancel_btn.setEnabled(False)
        self.engine.cancel()
        self.progress_text.setText("Cancelling after the current file…")
        self._on_log("Cancel requested.")

    def _backup_done(self, result):
        self._clock.stop()
        self.engine = None; self._pause_requested = False
        unattended = self._unattended; self._unattended = False
        self.pause_btn.setEnabled(False); self.pause_btn.setText("PAUSE"); self.cancel_btn.setEnabled(False)
        if self.tray_pause_action:
            self.tray_pause_action.setEnabled(False)
            self.tray_pause_action.setText("Pause backup")
        self.run_btn.setEnabled(bool(self.current_profile)); self.choose_sites_btn.setEnabled(True)
        ok = bool((result or {}).get("success")); cancelled = bool((result or {}).get("cancelled"))
        self.progress.setValue(100 if ok else self.progress.value())
        if self.tray:
            self.tray.setToolTip(
                "SMACK UP YOUR BACKUP — backup complete" if ok
                else "SMACK UP YOUR BACKUP — backup cancelled" if cancelled
                else "SMACK UP YOUR BACKUP — backup needs attention")
        self.progress_text.setText(
            "Backup completed and verified." if ok
            else "Backup cancelled. Verified files are kept for the next run." if cancelled
            else "Backup needs attention. Details are above.")
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
        schedule_page = self._page_widgets[5] if len(self._page_widgets) > 5 else None
        if schedule_page is not None and hasattr(schedule_page, "refresh"):
            schedule_page.refresh()
        if cancelled and not ok:
            return
        if not ok:
            if not unattended and self._offer_certificate_change(result, self._run_backup):
                return
            errors = "\n".join((result or {}).get("errors", [])) or "The backup did not complete."
            if self.isVisible() and not unattended:
                QMessageBox.warning(self, "Backup needs attention", errors[:1800])
            self._tray_message("Backup needs attention", errors[:500], critical=True)
        else:
            self._tray_message("Backup complete", "The backup completed and verified successfully.")

    # ── Restore ──────────────────────────────────────────────────────────

    def _restore_source_changed(self, *_):
        self.local_row.setVisible(self.src_local.isChecked())
        self.cloud_row.setVisible(self.src_cloud.isChecked())
        self.manual_row.setVisible(self.src_manual.isChecked())

    def _choose_restore(self):
        path, _ = QFileDialog.getOpenFileName(self, "Choose SUYB backup", self.dir_edit.text(), "SUYB backup (*.zip)")
        if path: self.restore_path.setText(path)

    def _choose_kit(self):
        path, _ = QFileDialog.getOpenFileName(self, "Choose recovery kit", self.dir_edit.text(), "Recovery kit (*.tar.gz);;All files (*.*)")
        if path: self.kit_path.setText(path)

    def _choose_media(self):
        path = QFileDialog.getExistingDirectory(self, "Choose media folder", self.dir_edit.text())
        if path: self.media_path.setText(path)

    def _browse_cloud(self):
        if not self.current_profile:
            QMessageBox.information(self, "No site", "Select a site first.")
            return
        dialog = CloudBrowserDialog(self, self._global_cloud(), profile=self.current_profile)
        if dialog.exec() == QDialog.Accepted and dialog.result:
            self._cloud_selected(*dialog.result)

    def _cloud_selected(self, file_id, name):
        self._cloud_file_id = file_id
        self.cloud_choice.setText(name)

    def _restore_requested(self, *args):
        """Manage page handed over a backup: switch Restore to it, the user confirms there."""
        if len(args) >= 2:
            self.src_cloud.setChecked(True); self._cloud_selected(args[0], args[1])
        elif args and os.path.isfile(str(args[0])):
            self.src_local.setChecked(True); self.restore_path.setText(str(args[0]))
        self._show_page(1)

    def _run_restore(self):
        if self.engine:
            QMessageBox.information(self, "A job is already running", "Wait for the running backup or restore to finish.")
            return
        if not self.current_profile:
            QMessageBox.warning(self, "Choose a site", "Select the site to restore first."); return
        if self.src_local.isChecked():
            path = self.restore_path.text().strip()
            if not os.path.isfile(path):
                QMessageBox.warning(self, "Choose a backup", "Choose an existing SUYB backup package first."); return
            source = os.path.basename(path)
            work = lambda engine: engine.restore_from_zip(path)
        elif self.src_cloud.isChecked():
            if not self._cloud_file_id:
                QMessageBox.warning(self, "No package", "Use Browse cloud… and select a backup package first."); return
            file_id = self._cloud_file_id
            download_dir = self.current_profile.get("backup_dir", "") or os.path.expanduser("~")
            source = self.cloud_choice.text()
            work = lambda engine: engine.restore_from_cloud(file_id, download_dir)
        else:
            kit = self.kit_path.text().strip(); media = self.media_path.text().strip()
            if not os.path.isfile(kit):
                QMessageBox.warning(self, "Missing file", "Choose a valid recovery kit (.tar.gz)."); return
            if not os.path.isdir(media):
                QMessageBox.warning(self, "Missing folder", "Choose a valid media folder."); return
            source = f"{os.path.basename(kit)} + {media}"
            work = lambda engine: engine.restore_from_kit(kit, media)
        if QMessageBox.question(self, "Restore this site?", f"Restore {self.current_profile['name']} from:\n{source}?\n\nThis writes files and database content to the selected site.", QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        self.restore_btn.setEnabled(False); self.restore_btn.setText("RESTORING…"); self.restore_cancel_btn.setEnabled(True)
        self.restore_log.clear(); self.restore_progress.setValue(0); self.restore_text.setText("Starting restore…")
        self.run_btn.setEnabled(False)
        restore_profile = dict(self.current_profile)
        restore_profile["api_key"] = self._resolved_key(restore_profile)
        self._restoring = True
        self.engine = restore_engine.RestoreEngine(
            restore_profile, global_cloud=self._global_cloud(),
            on_progress=lambda stage, msg, pct: self.bridge.restoreProgress.emit(msg, pct),
            on_log=lambda msg: self.bridge.restoreLog.emit(str(msg)))
        engine = self.engine
        threading.Thread(target=lambda: self.bridge.restoreFinished.emit(work(engine)), daemon=True).start()

    def _cancel_restore(self):
        if not (self.engine and self._restoring):
            return
        if QMessageBox.question(
                self, "Cancel this restore?",
                "Stop the restore now? The site may be left partly restored until you run it again.",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        self.engine.cancel(); self.restore_cancel_btn.setEnabled(False)
        self.restore_text.setText("Cancelling…")

    def _on_restore_progress(self, message, pct):
        self.restore_progress.setValue(int(max(0.0, min(1.0, float(pct))) * 100))
        self.restore_text.setText(message)

    def _on_restore_log(self, message):
        self.restore_log.append(message)

    def _restore_done(self, result):
        self.engine = None; self._restoring = False
        self.restore_btn.setEnabled(True); self.restore_btn.setText("REVIEW RESTORE"); self.restore_cancel_btn.setEnabled(False)
        self.run_btn.setEnabled(bool(self.current_profile))
        result = result or {}
        if result.get("success"):
            self.restore_progress.setValue(100)
            summary = (f"{result.get('uploaded', 0)} uploaded, {result.get('skipped', 0)} skipped, "
                       f"{result.get('failed', 0)} failed.")
            self.restore_text.setText("Restore complete. " + summary)
            self.restore_log.append("✓ Done — " + summary)
            QMessageBox.information(self, "Restore complete", "The site was restored and the operation completed successfully.\n\n" + summary)
        else:
            self.restore_text.setText("Restore needs attention. Details are below.")
            for error in result.get("errors", [])[:10]:
                self.restore_log.append(f"✗ {error}")
            if self._offer_certificate_change(result, self._run_restore):
                return
            errors = "\n".join(result.get("errors", [])) or "The restore did not complete."
            QMessageBox.warning(self, "Restore needs attention", errors[:1800])

    # ── Certificates ─────────────────────────────────────────────────────

    @staticmethod
    def _certificate_change(result):
        direct = (result or {}).get("certificate_change")
        if direct:
            return direct
        for item in (result or {}).get("profiles", []):
            if item.get("certificate_change"):
                return item["certificate_change"]
        return None

    def _offer_certificate_change(self, result, retry):
        change = self._certificate_change(result)
        if not change:
            return False
        box = QMessageBox(self)
        box.setWindowTitle("FTPS certificate changed")
        box.setIcon(QMessageBox.Warning)
        box.setText(f"The FTPS certificate for {change['host']} changed.")
        box.setInformativeText(
            "SUYB stopped before sending the password. Accept this only if you "
            "changed the server certificate or confirmed the change with your host.\n\n"
            f"Why it stopped: {change['why']}\n\n"
            f"Remembered:\n{change['old_fp']}\n\n"
            f"Offered now:\n{change['new_fp']}"
        )
        accept = box.addButton("ACCEPT NEW CERTIFICATE AND RETRY", QMessageBox.AcceptRole)
        cancel = box.addButton(QMessageBox.Cancel)
        box.setDefaultButton(cancel)
        box.exec()
        if box.clickedButton() is not accept:
            return True
        ftps_pins.accept_change(
            change["host"], int(change.get("port") or 21), change["new_fp"])
        QTimer.singleShot(0, retry)
        return True

    def _forget_certificate(self):
        profile = self.current_profile or {}
        host = str(profile.get("ftp_host", "")).strip()
        port = int(profile.get("ftp_port") or 21)
        if not host:
            QMessageBox.information(self, "No FTPS server", "The selected site has no FTPS server configured.")
            return
        store = ftps_pins.PinStore()
        if not store.get(host, port):
            QMessageBox.information(self, "No saved certificate", f"There is no saved FTPS certificate for {host}:{port}.")
            return
        if QMessageBox.question(
                self, "Forget FTPS certificate?",
                f"Forget the saved FTPS certificate for {host}:{port}?\n\n"
                "The next connection will remember whatever certificate that server presents.",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        store.forget(host, port)
        QMessageBox.information(self, "Certificate forgotten", f"The saved FTPS certificate for {host}:{port} was removed.")

    # ── Local backups / connection page ──────────────────────────────────

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
        p = dict(profile_manager.load_profile(self.current_profile["name"]) or self.current_profile)
        old_name = p.get("name", "")
        p.update({"name": self.name_edit.text().strip(), "site_url": self.url_edit.text().strip().rstrip("/"),
                  "api_key": self.key_edit.text().strip(), "backup_dir": self.dir_edit.text().strip()})
        if not p["name"] or not p["site_url"]:
            QMessageBox.warning(self, "Missing details", "Profile name and site URL are required."); return
        profile_manager.save_profile(p)
        if old_name and old_name != p["name"]:
            profile_manager.delete_profile(old_name)
        self._load_profiles(select=p["name"])
        self.connection.setText("● Saved · ready to verify"); self.connection.setObjectName("StatusGood")

    # ── Close ────────────────────────────────────────────────────────────

    def _save_window_state(self):
        if not self.isMinimized() and self.isVisible():
            self._window_settings.setValue("window/maximized", self.isMaximized())
            if not self.isMaximized():
                self._window_settings.setValue("window/normal_geometry", self.saveGeometry())
            self._window_settings.sync()

    def closeEvent(self, event):
        if self._quitting:
            event.accept(); return
        if self.engine:
            event.ignore()
            self.showMinimized()
            self._tray_message(
                "Backup still running",
                "SMACK UP YOUR BACKUP was minimized and remains available on the taskbar.")
            return
        # Settings → "Minimize to system tray instead of closing" (Tk behaviour),
        # honoured only when the tray icon really exists so the app is never stranded.
        cfg = config_module.load()
        if self.tray and self.tray.isVisible() and cfg.getboolean("app", "tray_enabled", fallback=False):
            self._save_window_state()
            event.ignore()
            self.hide()
            self._tray_message("Still running", "SMACK UP YOUR BACKUP is in the notification area. Scheduled backups keep running.")
            return
        event.accept()
        self._quit()


class _StartingEngine:
    """Stands in for the engine between START and the worker building the real one."""
    def cancel(self): pass
    def pause(self): pass
    def resume(self): pass


def _startup_gate():
    """What the Tk window did before reading any profile (SECAUDIT 037).

    1. Roll back a killed re-key / enable / disable of credential encryption.
    2. If encryption is on, unlock the vault with the passphrase first — a locked
       vault reads every password and key as blank, and saving a site while
       locked would write those blanks back.
    Returns True to continue, False to exit.
    """
    try:
        profile_manager.recover_pending_migration()
    except Exception as exc:
        QMessageBox.critical(
            None, "Credential migration recovery failed",
            "SUYB found an unfinished credential migration and could not restore it "
            f"safely. No sites were loaded.\n\n{exc}")
        return False
    if not secret_vault.is_enabled():
        return True
    if not secret_vault.crypto_available():
        QMessageBox.critical(
            None, "Encryption backend missing",
            "This install has an encrypted credential vault, but the 'cryptography' package "
            "isn't available, so credentials can't be decrypted. Reinstall SUYB with its dependencies.")
        return False
    while True:
        passphrase, ok = QInputDialog.getText(
            None, "Unlock SUYB", "Credential encryption is on.\nEnter your passphrase to unlock:",
            QLineEdit.Password)
        if not ok:
            if QMessageBox.question(
                    None, "Exit SUYB?",
                    "Without the passphrase SUYB can't read your saved credentials.\n\nExit now?",
                    QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes) == QMessageBox.Yes:
                return False
            continue
        if secret_vault.unlock(passphrase):
            return True
        QMessageBox.critical(
            None, "Wrong passphrase",
            "That passphrase didn't match. Try again, or Cancel to exit.\n\n"
            "Forgot it? Encrypted secrets cannot be recovered without the passphrase — "
            "you'd need to delete 'vault.meta' next to the app and re-enter your credentials.")


def run():
    app = QApplication.instance() or QApplication(sys.argv)
    app.setQuitOnLastWindowClosed(False)
    app.setApplicationName("SMACK UP YOUR BACKUP")
    app.setWindowIcon(QIcon(_icon_path()))
    app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    font = QFont("Segoe UI", 10); app.setFont(font)
    if not _startup_gate():
        return 0
    window = SuybWindow(); window.show()
    if window._restore_maximized:
        QTimer.singleShot(0, window.showMaximized)
    QTimer.singleShot(300, window._first_run)
    return app.exec()

# ===== SNAPSMACK EOF =====
