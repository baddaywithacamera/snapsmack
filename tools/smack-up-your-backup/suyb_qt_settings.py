"""SMACK UP YOUR BACKUP — Settings page (Qt).

Port of the Tk `SettingsTab` (main.py) to PySide6, following the PAGE CONTRACT
in suyb_qt_common.py.

What this page owns
-------------------
Per site (the site picked in the window header):
    snap_admin_user, snap_admin_pass, login_slug, backup_method, transport,
    ftp_host, ftp_port, ftp_user, ftp_pass, ftp_remote_dir, ftp_ssl,
    ftp_verify_cert, cloud_provider, cloud_credentials_file, cloud_folder_id
The Connection page owns name / site_url / api_key / backup_dir. Saving here
re-reads the profile from disk, changes ONLY the keys above and writes it back,
so nothing the Connection page (or the Schedule page) saved is overwritten.

For every site (config.ini — same sections and keys the Tk window wrote):
    [pacing] transfer_delay, batch_size
    [cloud]  provider, credentials_file, folder_id
    [app]    tray_enabled, startup_enabled, global_schedule_enabled,
             global_schedule_time

Credential encryption (SECAUDIT 037): Enable / Change passphrase / Disable call
profile_manager.enable_encryption / change_encryption_passphrase /
disable_encryption exactly as the Tk window did. Those functions write the
migration journal and roll back on failure themselves; this page only asks the
questions and reports the result.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import datetime
import json
import os
import shutil
import subprocess
import sys
from typing import Optional

from PySide6.QtCore import QTime
from PySide6.QtWidgets import (
    QButtonGroup, QCheckBox, QComboBox, QDialog, QFileDialog, QFormLayout,
    QHBoxLayout, QInputDialog, QLineEdit, QMessageBox, QPushButton,
    QRadioButton, QTimeEdit, QVBoxLayout, QWidget,
)

import config as config_module
import credential_store as cred_store
import profile_manager
import secret_vault
from _version import BUILD_VERSION
from suyb_qt_common import Relay, card, label, make_page, run_in_thread, set_status


# Keys the Connection page owns. This page never writes them.
CONNECTION_KEYS = ("name", "site_url", "api_key", "backup_dir")

# Plain text per-site fields: (key, label, password?)
_SIGNIN_FIELDS = (
    ("snap_admin_user", "Admin username", False),
    ("snap_admin_pass", "Admin password", True),
    ("login_slug", "Login slug", False),
)
_FTP_FIELDS = (
    ("ftp_host", "Host", False),
    ("ftp_port", "Port", False),
    ("ftp_user", "Username", False),
    ("ftp_pass", "Password", True),
    ("ftp_remote_dir", "Remote directory", False),
)
_TRANSPORTS = (
    ("http", "HTTP — through the backup key (no FTP login needed)"),
    ("ftp", "FTP"),
    ("sftp", "SFTP"),
)
_PROVIDERS = (("google_drive", "Google Drive"), ("none", "None"))
_METHODS = (
    ("ftp", "FTP — differential sync"),
    ("cloud", "Cloud — Google Drive"),
    ("local", "Local only — no upload"),
)


# ── Global cloud config (replaces App.global_cloud_config) ──────────────────
def global_cloud_from_config() -> dict:
    """The global cloud config for the cloud client factory, read from config.ini.

    Same shape as the Tk App.global_cloud_config(): provider falls back to
    "none", paths and folder are stripped."""
    cfg = config_module.load()
    return {
        "cloud_provider": cfg.get("cloud", "provider", fallback="none") or "none",
        "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback="").strip(),
        "cloud_folder_id": cfg.get("cloud", "folder_id", fallback="").strip(),
    }


# ── Dialog wrappers (one place, so tests can answer them) ───────────────────
def _ask_text(parent, title, prompt, password=True, initial=""):
    """Return the typed text, or None if the box was cancelled."""
    mode = QLineEdit.Password if password else QLineEdit.Normal
    text, ok = QInputDialog.getText(parent, title, prompt, mode, initial)
    return text if ok else None


def _ask_yes_no(parent, title, text):
    answer = QMessageBox.question(parent, title, text,
                                  QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
    return answer == QMessageBox.Yes


def _info(parent, title, text):
    QMessageBox.information(parent, title, text)


def _warn(parent, title, text):
    QMessageBox.warning(parent, title, text)


def _error(parent, title, text):
    QMessageBox.critical(parent, title, text)


def _status(widget, text):
    """Show an engine message; colour follows its leading mark."""
    text = str(text or "")
    if not text:
        widget.setText("")
        widget.setObjectName("Muted")
        widget.style().unpolish(widget); widget.style().polish(widget)
        return
    if text.startswith("✓"):
        set_status(widget, text, "good")
    elif text.startswith("✗") or text.lower().startswith(("error", "failed", "install failed")):
        set_status(widget, text, "bad")
    else:
        set_status(widget, text, "warn")


def _choice_combo(choices):
    combo = QComboBox()
    for value, text in choices:
        combo.addItem(text, value)
    return combo


def _set_combo_value(combo, value):
    """Select `value`; keep an unknown saved value as its own item so it round-trips."""
    value = "" if value is None else str(value)
    idx = combo.findData(value)
    if idx < 0:
        combo.addItem(value or "(not set)", value)
        idx = combo.count() - 1
    combo.setCurrentIndex(idx)


def _refresh_cred_combo(combo, current_path=""):
    """Repopulate a saved-credentials dropdown and select the entry for `current_path`."""
    combo.blockSignals(True)
    combo.clear()
    combo.addItem("— pick saved credentials —", "")
    for name in cred_store.names():
        combo.addItem(name, name)
    name = cred_store.name_for(current_path) if current_path else None
    idx = combo.findData(name) if name else 0
    combo.setCurrentIndex(idx if idx >= 0 else 0)
    combo.blockSignals(False)


def _offer_save_to_library(parent, path):
    try:
        from suyb_qt_credentials import offer_save_to_library
    except ImportError:
        return
    offer_save_to_library(parent, path)


def _choose_credential(parent):
    try:
        from suyb_qt_credentials import choose_credential
    except ImportError:
        _error(parent, "Credential library", "The credential library window is not available in this build.")
        return None
    return choose_credential(parent)


# ── Hub discovery dialog (port of HubDiscoveryDialog) ───────────────────────
class HubDiscoveryDialog(QDialog):
    """Connect to a hub blog, list its spokes, create a profile for each."""

    def __init__(self, parent, global_cloud):
        super().__init__(parent)
        self.setWindowTitle("Discover from Hub")
        self.setModal(True)
        self.resize(560, 330)
        self.created_count = 0
        self._global_cloud = global_cloud
        self._relay = Relay(self)
        self._running = False

        col = QVBoxLayout(self)
        col.addWidget(label("Hub Connection", "CardTitle"))
        col.addWidget(label("Enter the hub blog's URL and its API key. SUYB pulls every spoke, "
                            "fills in each one's key, and points them all at your cloud settings "
                            "for every site.", "Muted"))
        form = QFormLayout()
        self.url_edit = QLineEdit()
        self.key_edit = QLineEdit(); self.key_edit.setEchoMode(QLineEdit.Password)
        form.addRow("Hub URL", self.url_edit)
        form.addRow("Hub API key", self.key_edit)
        dir_row = QHBoxLayout()
        self.dir_edit = QLineEdit(os.path.join(
            (os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack", "staging"))
        browse = QPushButton("Browse…"); browse.clicked.connect(self._browse_dir)
        dir_row.addWidget(self.dir_edit, 1); dir_row.addWidget(browse)
        form.addRow("Backup base folder", dir_row)
        col.addLayout(form)
        self.status = label("", "Muted")
        col.addWidget(self.status)
        buttons = QHBoxLayout(); buttons.addStretch(1)
        self.go_btn = QPushButton("Discover"); self.go_btn.setObjectName("Primary")
        self.go_btn.clicked.connect(self._discover)
        self.cancel_btn = QPushButton("Close"); self.cancel_btn.clicked.connect(self.reject)
        buttons.addWidget(self.go_btn); buttons.addWidget(self.cancel_btn)
        col.addLayout(buttons)

    def prefill(self, current_profile):
        # The Hub's shared store first (filled in THE HUB app), then the current site.
        hub_url = config_module.shared_cred("hub_url")
        hub_key = config_module.shared_cred("hub_key")
        cp = current_profile or {}
        if not hub_url:
            hub_url = cp.get("site_url", "")
        if not hub_key:
            hub_key = cp.get("api_key", "")
        self.url_edit.setText(hub_url or "")
        self.key_edit.setText(hub_key or "")

    def reject(self):
        if self._running:
            _info(self, "Still working", "Discovery is still running. Wait for it to finish.")
            return
        super().reject()

    def _browse_dir(self):
        d = QFileDialog.getExistingDirectory(self, "Choose backup base folder", self.dir_edit.text())
        if d:
            self.dir_edit.setText(d)

    def _discover(self):
        url = self.url_edit.text().strip()
        key = self.key_edit.text().strip()
        if not url or not key:
            _error(self, "Required", "Hub URL and API key are required.")
            return
        base_dir = self.dir_edit.text().strip()
        self._running = True
        self.go_btn.setEnabled(False)
        _status(self.status, "Connecting to hub…")
        relay = self._relay

        def say(text):
            relay.call.emit(lambda t=text: _status(self.status, t))

        def work():
            from hub_discovery import HubDiscovery, build_profiles_from_spokes
            disc = HubDiscovery(url, api_key=key)
            say("Connected. Fetching spoke list…")
            hub_info, spokes = disc.discover_spokes()
            spoke_configs = {}
            for i, spoke in enumerate(spokes):
                spoke_url = spoke.get("site_url", "").rstrip("/")
                api_key = spoke.get("api_key_remote", "")
                say(f"Querying spoke {i + 1}/{len(spokes)}: {spoke.get('site_name', '?')}…")
                if spoke_url and api_key:
                    cfg = disc.fetch_spoke_backup_config(spoke_url, api_key)
                    if cfg:
                        spoke_configs[spoke_url] = cfg
            disc.close()
            return build_profiles_from_spokes(
                hub_info, spokes, spoke_configs, base_dir,
                global_cloud=self._global_cloud, hub_api_key=key)

        run_in_thread(relay, work, self._done, self._failed)

    def _done(self, profiles):
        self._running = False
        self.go_btn.setEnabled(True)
        if not profiles:
            _status(self.status, "No blogs found.")
            return
        existing = set(profile_manager.list_profiles())
        created = skipped = 0
        for p in profiles:
            name = p.get("name", "")
            if name in existing:
                skipped += 1
                continue
            profile_manager.save_profile(p)
            created += 1
            existing.add(name)
        self.created_count += created
        msg = f"Done! Created {created} profile(s)."
        if skipped:
            msg += f" Skipped {skipped} existing."
        _status(self.status, "✓ " + msg)
        if created > 0:
            _info(self, "Discovery complete",
                  f"{msg}\n\nYou'll still need to enter FTP credentials and backup "
                  "folders for each spoke if they weren't filled in automatically.")

    def _failed(self, exc):
        self._running = False
        self.go_btn.setEnabled(True)
        _status(self.status, f"Error: {exc}")


# ── The page ────────────────────────────────────────────────────────────────
class SettingsPage(QWidget):
    def __init__(self, window):
        super().__init__()
        self.window_ref = window
        self._relay = Relay(self)
        self._profile_name = ""
        self._ai_checked = False
        self._applied_sched_time = None
        self._edits = {}
        layout = make_page(
            self, "Settings",
            "Sign-in, transfer and cloud settings for the selected site, plus the settings "
            "that apply to every site. Site name, address, backup key and working folder "
            "are on the Connection page.")
        self._build_site_card(layout)
        self._build_method_card(layout)
        self._build_defaults_card(layout)
        self._build_encryption_card(layout)
        self._build_schedule_card(layout)
        self._build_hub_card(layout)
        self._build_ai_card(layout)
        self._build_export_card(layout)
        layout.addWidget(label(f"Smack Up Your Backup  v{BUILD_VERSION}", "Muted"))
        layout.addStretch(1)
        self._on_method_change()
        signal = getattr(window, "profileChanged", None)
        if signal is not None:
            signal.connect(self._on_profile_changed)

    # ── Build ───────────────────────────────────────────────────────────
    def _line(self, key, password=False):
        edit = QLineEdit()
        if password:
            edit.setEchoMode(QLineEdit.Password)
        self._edits[key] = edit
        return edit

    def _build_site_card(self, layout):
        frame, col = card("This site's admin sign-in",
                          "Used by Test Login and by Pull Cloud Config. Saved with the site.")
        self.site_card = frame
        self.site_notice = label("", "Muted")
        col.addWidget(self.site_notice)
        form = QFormLayout()
        for key, text, pw in _SIGNIN_FIELDS:
            form.addRow(text, self._line(key, pw))
        col.addLayout(form)
        row = QHBoxLayout()
        self.test_login_btn = QPushButton("Test Login"); self.test_login_btn.clicked.connect(self._test_login)
        self.test_ftp_btn = QPushButton("Test FTP"); self.test_ftp_btn.clicked.connect(self._test_ftp)
        row.addWidget(self.test_login_btn); row.addWidget(self.test_ftp_btn); row.addStretch(1)
        col.addLayout(row)
        self.conn_status = label("", "Muted")
        col.addWidget(self.conn_status)
        layout.addWidget(frame)

    def _build_method_card(self, layout):
        frame, col = card("Backup method", "Saved per site.")
        self.method_card = frame
        self.method_group = QButtonGroup(self)
        self.method_buttons = {}
        for value, text in _METHODS:
            radio = QRadioButton(text)
            self.method_group.addButton(radio)
            self.method_buttons[value] = radio
            radio.toggled.connect(self._on_method_change)
            col.addWidget(radio)
        self.method_buttons["ftp"].setChecked(True)

        # FTP fields
        self.ftp_box = QWidget()
        ftp_col = QVBoxLayout(self.ftp_box); ftp_col.setContentsMargins(0, 6, 0, 0)
        form = QFormLayout()
        self.transport_combo = _choice_combo(_TRANSPORTS)
        self.transport_combo.currentIndexChanged.connect(self._on_transport_change)
        form.addRow("Protocol", self.transport_combo)
        for key, text, pw in _FTP_FIELDS:
            form.addRow(text, self._line(key, pw))
        ftp_col.addLayout(form)
        checks = QHBoxLayout()
        self.ftp_ssl_chk = QCheckBox("Use FTP_TLS"); self.ftp_ssl_chk.setChecked(True)
        self.ftp_verify_chk = QCheckBox("Verify certificate")
        checks.addWidget(self.ftp_ssl_chk); checks.addWidget(self.ftp_verify_chk); checks.addStretch(1)
        ftp_col.addLayout(checks)
        col.addWidget(self.ftp_box)

        # Cloud fields
        self.cloud_box = QWidget()
        cloud_form = QFormLayout(self.cloud_box); cloud_form.setContentsMargins(0, 6, 0, 0)
        self.cloud_provider_combo = _choice_combo(_PROVIDERS)
        cloud_form.addRow("Provider", self.cloud_provider_combo)
        pick_row = QHBoxLayout()
        self.profile_creds_combo = QComboBox()
        self.profile_creds_combo.activated.connect(self._on_profile_cred_selected)
        lib_btn = QPushButton("Manage Library…"); lib_btn.clicked.connect(self._manage_profile_library)
        pick_row.addWidget(self.profile_creds_combo, 1); pick_row.addWidget(lib_btn)
        cloud_form.addRow("Saved credentials", pick_row)
        path_row = QHBoxLayout()
        creds_edit = self._line("cloud_credentials_file")
        creds_edit.editingFinished.connect(self._validate_profile_creds)
        browse = QPushButton("Browse…"); browse.clicked.connect(self._browse_credentials)
        path_row.addWidget(creds_edit, 1); path_row.addWidget(browse)
        cloud_form.addRow("Creds override (optional)", path_row)
        self.profile_creds_status = label("", "Muted")
        cloud_form.addRow("", self.profile_creds_status)
        self.profile_auth_btn = QPushButton("Authenticate with Google")
        self.profile_auth_btn.clicked.connect(self._authenticate_oauth_profile)
        self.profile_auth_btn.setVisible(False)
        cloud_form.addRow("", self.profile_auth_btn)
        cloud_form.addRow("Cloud folder ID", self._line("cloud_folder_id"))
        col.addWidget(self.cloud_box)

        self.local_note = label("", "Muted")
        col.addWidget(self.local_note)
        col.addWidget(label("Automatic per-site schedules are on the Schedule page.", "Muted"))
        row = QHBoxLayout(); row.addStretch(1)
        self.save_site_btn = QPushButton("Save site settings"); self.save_site_btn.setObjectName("Primary")
        self.save_site_btn.clicked.connect(self._save_profile)
        row.addWidget(self.save_site_btn)
        col.addLayout(row)
        self.profile_status = label("", "Muted")
        col.addWidget(self.profile_status)
        layout.addWidget(frame)

    def _build_defaults_card(self, layout):
        frame, col = card("Defaults for every site",
                          "Shared by all sites unless a site sets its own. The cloud key file "
                          "stays on this computer — it is never uploaded.")
        form = QFormLayout()
        self.delay_edit = QLineEdit(); self.batch_edit = QLineEdit()
        form.addRow("Pacing delay (sec)", self.delay_edit)
        form.addRow("Batch size (0 = all)", self.batch_edit)
        self.gc_provider_combo = _choice_combo(_PROVIDERS)
        form.addRow("Cloud provider", self.gc_provider_combo)
        pick_row = QHBoxLayout()
        self.gc_creds_combo = QComboBox()
        self.gc_creds_combo.activated.connect(self._on_gc_cred_selected)
        lib_btn = QPushButton("Manage Library…"); lib_btn.clicked.connect(self._manage_gc_library)
        pick_row.addWidget(self.gc_creds_combo, 1); pick_row.addWidget(lib_btn)
        form.addRow("Saved credentials", pick_row)
        path_row = QHBoxLayout()
        self.gc_creds_edit = QLineEdit()
        self.gc_creds_edit.editingFinished.connect(self._validate_global_key)
        browse = QPushButton("Browse…"); browse.clicked.connect(self._browse_global_key)
        path_row.addWidget(self.gc_creds_edit, 1); path_row.addWidget(browse)
        form.addRow("Credentials JSON", path_row)
        self.gc_key_status = label("", "Muted")
        form.addRow("", self.gc_key_status)
        self.auth_btn = QPushButton("Authenticate with Google")
        self.auth_btn.clicked.connect(self._authenticate_oauth)
        self.auth_btn.setVisible(False)
        form.addRow("", self.auth_btn)
        self.gc_folder_edit = QLineEdit()
        form.addRow("Folder ID", self.gc_folder_edit)
        col.addLayout(form)
        row = QHBoxLayout()
        self.test_cloud_btn = QPushButton("Test cloud"); self.test_cloud_btn.clicked.connect(self._test_cloud)
        row.addWidget(self.test_cloud_btn); row.addStretch(1)
        save = QPushButton("Save defaults"); save.setObjectName("Primary"); save.clicked.connect(self._save)
        row.addWidget(save)
        col.addLayout(row)
        self.gc_test_status = label("", "Muted")
        col.addWidget(self.gc_test_status)
        layout.addWidget(frame)

    def _build_encryption_card(self, layout):
        frame, col = card("Credential encryption",
                          "Encrypt saved FTP / admin / API / cloud credentials with a passphrase, "
                          "so a lost thumb drive can't reveal them.")
        self.enc_body = QWidget()
        self.enc_layout = QVBoxLayout(self.enc_body); self.enc_layout.setContentsMargins(0, 0, 0, 0)
        col.addWidget(self.enc_body)
        self.enc_status = None
        self.enc_mk_chk = None
        layout.addWidget(frame)

    def _build_schedule_card(self, layout):
        frame, col = card("Automatic backups",
                          "One switch backs up every blog on a daily timer — and it runs even "
                          "when SUYB is closed.")
        row = QHBoxLayout()
        self.global_sched_chk = QCheckBox("Back up ALL blogs automatically, daily at")
        self.global_sched_chk.clicked.connect(self._on_global_schedule_toggle)
        self.global_sched_time = QTimeEdit(); self.global_sched_time.setDisplayFormat("HH:mm")
        self.global_sched_time.setTime(QTime(2, 0))
        self.global_sched_time.editingFinished.connect(self._on_schedule_time_edited)
        row.addWidget(self.global_sched_chk); row.addWidget(self.global_sched_time); row.addStretch(1)
        col.addLayout(row)
        self.global_sched_status = label("", "Muted")
        col.addWidget(self.global_sched_status)
        self.tray_chk = QCheckBox("Minimize to system tray instead of closing")
        self.tray_chk.clicked.connect(self._on_tray_toggle)
        self.startup_chk = QCheckBox("Launch SUYB when Windows starts")
        self.startup_chk.clicked.connect(self._on_startup_toggle)
        col.addWidget(self.tray_chk); col.addWidget(self.startup_chk)
        layout.addWidget(frame)

    def _build_hub_card(self, layout):
        frame, col = card("Hub / spoke discovery",
                          "Find every spoke of a hub blog and create a site for each. "
                          "Pull Cloud Config copies the selected site's cloud settings from "
                          "the blog into the Backup method card (then Save site settings).")
        row = QHBoxLayout()
        disc = QPushButton("Discover from Hub…"); disc.clicked.connect(self._discover_from_hub)
        self.pull_btn = QPushButton("Pull Cloud Config"); self.pull_btn.clicked.connect(self._pull_cloud_config)
        row.addWidget(disc); row.addWidget(self.pull_btn); row.addStretch(1)
        col.addLayout(row)
        self.disc_status = label("", "Muted")
        col.addWidget(self.disc_status)
        layout.addWidget(frame)

    def _build_ai_card(self, layout):
        frame, col = card("AI file matching")
        self.ai_status = label("Not checked yet.", "Muted")
        col.addWidget(self.ai_status)
        row = QHBoxLayout()
        self.ai_btn = QPushButton("Install  (pip install sentence-transformers)")
        self.ai_btn.clicked.connect(self._install_ai)
        row.addWidget(self.ai_btn); row.addStretch(1)
        col.addLayout(row)
        layout.addWidget(frame)

    def _build_export_card(self, layout):
        frame, col = card("Export / import settings",
                          "One JSON file with every site and the settings for every site. "
                          "Keep it private — it holds your sites' backup keys.")
        row = QHBoxLayout()
        exp = QPushButton("Export All…"); exp.clicked.connect(self._export_settings)
        imp = QPushButton("Import…"); imp.clicked.connect(self._import_settings)
        row.addWidget(exp); row.addWidget(imp); row.addStretch(1)
        col.addLayout(row)
        layout.addWidget(frame)

    # ── Contract ────────────────────────────────────────────────────────
    def refresh(self):
        """Load from disk the first time the page is shown.

        Later visits keep what is on screen: reloading on every visit threw away
        unsaved edits whenever a page button was clicked, which is easy to do by
        accident. A site change still reloads (_on_profile_changed).
        """
        if not getattr(self, "_loaded_once", False):
            self.load_config()
            self._load_current_profile()
            self._loaded_once = True
        self._enc_render()
        if not self._ai_checked:
            self._refresh_ai_status()

    def _on_profile_changed(self, _profile):
        self._load_current_profile()

    def help_topics(self):
        return list(HELP_TOPICS)

    def shutdown(self):
        return None

    def _busy(self):
        busy = getattr(self.window_ref, "busy", None)
        try:
            return bool(busy()) if callable(busy) else False
        except Exception:
            return False

    def _global_cloud(self):
        getter = getattr(self.window_ref, "global_cloud", None)
        if callable(getter):
            try:
                return getter()
            except Exception:
                pass
        return global_cloud_from_config()

    # ── Load ────────────────────────────────────────────────────────────
    def load_config(self):
        cfg = config_module.load()
        self.delay_edit.setText(cfg.get("pacing", "transfer_delay", fallback="2"))
        self.batch_edit.setText(cfg.get("pacing", "batch_size", fallback="0"))
        _set_combo_value(self.gc_provider_combo, cfg.get("cloud", "provider", fallback="google_drive"))
        gc_path = cfg.get("cloud", "credentials_file", fallback="")
        self.gc_creds_edit.setText(gc_path)
        self.gc_folder_edit.setText(cfg.get("cloud", "folder_id", fallback=""))
        _refresh_cred_combo(self.gc_creds_combo, gc_path)
        self.tray_chk.setChecked(cfg.getboolean("app", "tray_enabled", fallback=False))
        self.startup_chk.setChecked(cfg.getboolean("app", "startup_enabled", fallback=False))
        self.global_sched_chk.setChecked(cfg.getboolean("app", "global_schedule_enabled", fallback=False))
        time_str = cfg.get("app", "global_schedule_time", fallback="02:00")
        parsed = QTime.fromString(time_str.strip(), "HH:mm")
        if not parsed.isValid():
            parsed = QTime.fromString(time_str.strip(), "H:mm")
        self.global_sched_time.setTime(parsed if parsed.isValid() else QTime(2, 0))
        self._applied_sched_time = self._sched_time_str()
        self._validate_global_key()

    def _load_current_profile(self):
        current = getattr(self.window_ref, "current_profile", None)
        profile = None
        if current and current.get("name"):
            try:
                profile = profile_manager.load_profile(current["name"])
            except Exception:
                profile = None
            if profile is None:
                profile = dict(current)
        self.load_profile(profile)

    def load_profile(self, profile: Optional[dict]):
        """Fill the per-site fields from a profile dict (or clear and disable them)."""
        has = bool(profile)
        for widget in (self.site_card, self.method_card):
            widget.setEnabled(has)
        self.pull_btn.setEnabled(has)
        if not has:
            self._profile_name = ""
            for edit in self._edits.values():
                edit.setText("")
            self.site_notice.setText("Choose a site at the top of the window first.")
            self.profile_status.setText("")
            self.conn_status.setText("")
            _status(self.profile_creds_status, "")
            self.profile_auth_btn.setVisible(False)
            return
        self._profile_name = profile.get("name", "")
        self.site_notice.setText("")
        for key, edit in self._edits.items():
            edit.setText(str(profile.get(key, "")))
        # transport: legacy profiles without the key are FTP (as Tk). Signals
        # blocked so loading never runs the 21<->22 port swap on the saved port.
        self.transport_combo.blockSignals(True)
        _set_combo_value(self.transport_combo, profile.get("transport", "ftp"))
        self.transport_combo.blockSignals(False)
        _set_combo_value(self.cloud_provider_combo, profile.get("cloud_provider", ""))
        for chk, key, default in ((self.ftp_ssl_chk, "ftp_ssl", True),
                                  (self.ftp_verify_chk, "ftp_verify_cert", False)):
            val = profile.get(key, default)
            chk.setChecked(bool(val) if val != "" else default)
        saved = profile.get("backup_method", "")
        if saved not in ("ftp", "cloud", "local"):
            prov = profile.get("cloud_provider", "none")
            if prov and prov != "none":
                saved = "cloud"
            elif profile.get("ftp_host"):
                saved = "ftp"
            else:
                saved = "local"
        self.method_buttons[saved].setChecked(True)
        self._on_method_change()
        self._sync_tls_enabled()
        _refresh_cred_combo(self.profile_creds_combo, profile.get("cloud_credentials_file", ""))
        self._validate_profile_creds()
        self.profile_status.setText(f"Editing: {self._profile_name}")

    # ── Per-site form behaviour ─────────────────────────────────────────
    def _method(self):
        for value, radio in self.method_buttons.items():
            if radio.isChecked():
                return value
        return "ftp"

    def _on_method_change(self, *_):
        # The radio's first setChecked fires this before the boxes it shows and
        # hides exist; the build calls it again once they do.
        if not hasattr(self, "cloud_box") or not hasattr(self, "local_note"):
            return
        method = self._method()
        self.ftp_box.setVisible(method == "ftp")
        self.cloud_box.setVisible(method == "cloud")
        self.local_note.setText(
            "Cloud backups use the working folder (Connection page) as a staging area."
            if method == "cloud" else
            "Backups are saved in the working folder set on the Connection page.")

    def _on_transport_change(self, *_):
        """Swap 21<->22 only when the port is still the other protocol's default."""
        self._sync_tls_enabled()
        t = self.transport_combo.currentData()
        port = self._edits["ftp_port"]
        cur = port.text().strip()
        if t == "sftp" and cur in ("", "21"):
            port.setText("22")
        elif t == "ftp" and cur in ("", "22"):
            port.setText("21")

    def _sync_tls_enabled(self):
        """FTP_TLS and certificate checks apply to plain FTP only (SFTP is SSH-encrypted)."""
        enabled = self.transport_combo.currentData() != "sftp"
        self.ftp_ssl_chk.setEnabled(enabled)
        self.ftp_verify_chk.setEnabled(enabled)

    def collect_profile_fields(self) -> dict:
        """The per-site values this page owns, converted exactly as Tk saved them."""
        out = {}
        for key, edit in self._edits.items():
            val = edit.text()
            if key == "ftp_port":
                try:
                    val = int(val)
                except ValueError:
                    pass
            out[key] = val
        out["transport"] = self.transport_combo.currentData()
        out["cloud_provider"] = self.cloud_provider_combo.currentData()
        out["ftp_ssl"] = bool(self.ftp_ssl_chk.isChecked())
        out["ftp_verify_cert"] = bool(self.ftp_verify_chk.isChecked())
        method = self._method()
        out["backup_method"] = method
        if method == "local":
            out["cloud_provider"] = "none"
        return out

    def _save_profile(self):
        name = self._profile_name
        if not name:
            _warn(self, "No site selected", "Choose a site at the top of the window first.")
            return
        try:
            fresh = profile_manager.load_profile(name)
        except Exception as exc:
            _error(self, "Could not read site", f"The saved settings for \"{name}\" could not be read:\n{exc}")
            return
        if fresh is None:
            _error(self, "Site not found", f"There is no saved site called \"{name}\".")
            return
        fields = self.collect_profile_fields()
        for key in CONNECTION_KEYS:
            fields.pop(key, None)
        fresh.update(fields)
        try:
            profile_manager.save_profile(fresh)
        except Exception as exc:
            _error(self, "Not saved", f"The settings for \"{name}\" were NOT saved:\n{exc}")
            return
        reload = getattr(self.window_ref, "reload_profiles", None)
        if callable(reload):
            reload(name)
        self.profile_status.setText(f"Editing: {name}")
        _info(self, "Saved", f"Settings for \"{name}\" saved.")

    def _live_profile(self):
        """The selected site as saved, with this page's unsaved edits on top."""
        base = dict(getattr(self.window_ref, "current_profile", None) or {})
        base.update(self.collect_profile_fields())
        return base

    def _test_login(self):
        profile = self._live_profile()
        url = str(profile.get("site_url", "")).strip().rstrip("/")
        user = self._edits["snap_admin_user"].text().strip()
        pw = self._edits["snap_admin_pass"].text()
        slug = (self._edits["login_slug"].text().strip() or "snap-in").strip("/") or "snap-in"
        api_key = str(profile.get("api_key", "")).strip()
        if not url or not (api_key or (user and pw)):
            _status(self.conn_status, "Fill in the site URL (Connection page), and a backup key or admin username and password first")
            return
        _status(self.conn_status, "Testing login…")

        def work():
            import backup_engine
            sess = backup_engine.SnapSmackSession(url, api_key=api_key, login_slug=slug)
            if api_key:
                r = sess.session.get(f"{url}/suyb-export.php?type=schema", timeout=15,
                                     allow_redirects=False, stream=True)
                code = r.status_code
                r.close()
                if code == 200:
                    return "✓ API key valid"
                if code in (401, 403):
                    return f"⚠ API key rejected (HTTP {code})"
                if code in (301, 302, 303, 307, 308):
                    return "⚠ API key not accepted — redirected to login"
                return f"⚠ HTTP {code}"
            sess.login(user, pw)   # raises on bad credentials
            return "✓ Login successful"

        run_in_thread(self._relay, work,
                      lambda msg: _status(self.conn_status, msg),
                      lambda exc: _status(self.conn_status, f"✗ {exc}"))

    def _test_ftp(self):
        profile = self._live_profile()
        host = str(profile.get("ftp_host", "")).strip()
        if not host:
            _status(self.conn_status, "Fill in host first")
            return
        is_sftp = str(profile.get("transport", "ftp")).lower() == "sftp"
        proto = "SFTP" if is_sftp else "FTP"
        try:
            port = int(profile.get("ftp_port") or (22 if is_sftp else 21))
        except (TypeError, ValueError):
            _status(self.conn_status, "✗ The port must be a number")
            return
        _status(self.conn_status, f"Connecting to {host}:{port} ({proto})…")

        def work():
            import transport
            client = transport.make_client(profile, transfer_delay=0)
            client.connect()
            client.disconnect()
            return f"✓ Connected to {host} ({proto})"

        run_in_thread(self._relay, work,
                      lambda msg: _status(self.conn_status, msg),
                      lambda exc: _status(self.conn_status, f"✗ {host}:{port} — {exc}"))

    # ── Credentials files (per site) ────────────────────────────────────
    def _on_profile_cred_selected(self, _index=None):
        name = self.profile_creds_combo.currentData()
        path = cred_store.path_for(name) if name else None
        if path:
            self._edits["cloud_credentials_file"].setText(path)
            self._validate_profile_creds()

    def _manage_profile_library(self):
        picked = _choose_credential(self)
        path_now = self._edits["cloud_credentials_file"].text().strip()
        if picked:
            _name, path = picked
            self._edits["cloud_credentials_file"].setText(path)
            path_now = path
        _refresh_cred_combo(self.profile_creds_combo, path_now)
        _refresh_cred_combo(self.gc_creds_combo, self.gc_creds_edit.text().strip())
        self._validate_profile_creds()

    def _browse_credentials(self):
        path, _ = QFileDialog.getOpenFileName(self, "Select credentials JSON", "",
                                              "JSON files (*.json);;All files (*.*)")
        if path:
            self._edits["cloud_credentials_file"].setText(path)
            _refresh_cred_combo(self.profile_creds_combo, path)
            self._validate_profile_creds()

    def _validate_profile_creds(self):
        self._validate_creds(self._edits["cloud_credentials_file"].text().strip(),
                             self.profile_creds_status, self.profile_auth_btn,
                             lambda: self._edits["cloud_credentials_file"].text().strip())

    def _authenticate_oauth_profile(self):
        self._run_oauth(self._edits["cloud_credentials_file"].text().strip(),
                        self.profile_creds_status, self.profile_auth_btn,
                        self._validate_profile_creds, self.profile_creds_combo)

    # ── Credentials files (every site) ──────────────────────────────────
    def _on_gc_cred_selected(self, _index=None):
        name = self.gc_creds_combo.currentData()
        path = cred_store.path_for(name) if name else None
        if path:
            self.gc_creds_edit.setText(path)
            self._validate_global_key()

    def _manage_gc_library(self):
        picked = _choose_credential(self)
        if picked:
            _name, path = picked
            self.gc_creds_edit.setText(path)
        _refresh_cred_combo(self.gc_creds_combo, self.gc_creds_edit.text().strip())
        _refresh_cred_combo(self.profile_creds_combo, self._edits["cloud_credentials_file"].text().strip())
        self._validate_global_key()

    def _browse_global_key(self):
        path, _ = QFileDialog.getOpenFileName(self, "Select credentials JSON", "",
                                              "JSON files (*.json);;All files (*.*)")
        if path:
            self.gc_creds_edit.setText(path)
            _refresh_cred_combo(self.gc_creds_combo, path)
            self._validate_global_key()

    def _validate_global_key(self):
        self._validate_creds(self.gc_creds_edit.text().strip(), self.gc_key_status,
                             self.auth_btn, lambda: self.gc_creds_edit.text().strip())

    def _authenticate_oauth(self):
        self._run_oauth(self.gc_creds_edit.text().strip(), self.gc_key_status,
                        self.auth_btn, self._validate_global_key, self.gc_creds_combo)

    # ── Shared credential checks ────────────────────────────────────────
    def _validate_creds(self, path, status, auth_btn, current_path):
        """Name the credentials file type; for OAuth files, show the sign-in state.

        The OAuth token check may refresh the token over the network, so it
        runs on a worker thread; a stale answer (path changed since) is dropped."""
        import cloud_client as cc
        if not path:
            _status(status, "")
            auth_btn.setVisible(False)
            return
        if cc._is_service_account_key(path):
            _status(status, "✓ Valid service account key")
            auth_btn.setVisible(False)
        elif cc._is_oauth_client_secret(path):
            _status(status, "Checking sign-in…")
            auth_btn.setVisible(True)

            def done(token_status):
                if current_path() == path:
                    _status(status, token_status or "OAuth client secret — click Authenticate")

            run_in_thread(self._relay, lambda: cc.get_oauth_token_status(path), done,
                          lambda exc: done(f"Token error: {exc}"))
        elif os.path.isfile(path):
            _status(status, "Unrecognised format — expected an OAuth or service account JSON")
            auth_btn.setVisible(False)
        else:
            _status(status, "File not found")
            auth_btn.setVisible(False)

    def _run_oauth(self, path, status, auth_btn, revalidate, combo):
        """Google consent flow on a worker thread (it opens a browser — expected)."""
        import cloud_client as cc
        if not path:
            return
        _status(status, "Opening browser for Google login…")
        auth_btn.setEnabled(False)

        def done(result):
            success, msg = result
            _status(status, msg)
            if success:
                revalidate()
                _offer_save_to_library(self, path)
                _refresh_cred_combo(combo, path)
            auth_btn.setEnabled(True)

        def failed(exc):
            _status(status, f"Authentication failed: {exc}")
            auth_btn.setEnabled(True)

        run_in_thread(self._relay, lambda: cc.authenticate_oauth(path), done, failed)

    def _test_cloud(self):
        gc = self.global_cloud_config()
        if not gc["cloud_credentials_file"]:
            _status(self.gc_test_status, "Set the Credentials JSON first")
            return
        gc["cloud_provider"] = self.gc_provider_combo.currentData()
        _status(self.gc_test_status, "Testing…")

        def work():
            import cloud_client as _cc
            client = _cc.get_cloud_client({}, global_cloud=gc)
            if not client:
                return "Set provider + credentials JSON first", None
            if not hasattr(client, "test_connection"):
                return f"Test not supported for provider '{gc['cloud_provider']}'", None
            ok, msg = client.test_connection()
            folder = None
            if ok and hasattr(client, "resolved_folder_id"):
                try:
                    folder = client.resolved_folder_id()
                except Exception:
                    folder = None
            return msg, folder

        def done(result):
            msg, folder = result
            _status(self.gc_test_status, msg)
            if folder:
                self.gc_folder_edit.setText(folder)   # Save defaults keeps it

        run_in_thread(self._relay, work, done,
                      lambda exc: _status(self.gc_test_status, f"✗ {exc}"))

    def global_cloud_config(self) -> dict:
        """Live (unsaved) global cloud values — same shape as Tk global_cloud_config."""
        return {
            "cloud_provider": self.gc_provider_combo.currentData() or "none",
            "cloud_credentials_file": self.gc_creds_edit.text().strip(),
            "cloud_folder_id": self.gc_folder_edit.text().strip(),
        }

    def _save(self):
        cfg = config_module.load()
        for section in ("pacing", "cloud"):
            if not cfg.has_section(section):
                cfg.add_section(section)
        cfg.set("pacing", "transfer_delay", self.delay_edit.text())
        cfg.set("pacing", "batch_size", self.batch_edit.text())
        cfg.set("cloud", "provider", self.gc_provider_combo.currentData() or "")
        cfg.set("cloud", "credentials_file", self.gc_creds_edit.text().strip())
        cfg.set("cloud", "folder_id", self.gc_folder_edit.text().strip())
        try:
            config_module.save(cfg)
        except Exception as exc:
            _error(self, "Not saved", f"The defaults were NOT saved:\n{exc}")
            return
        _info(self, "Saved", "Defaults for every site saved.")

    # ── Credential encryption ───────────────────────────────────────────
    def _enc_clear(self):
        while self.enc_layout.count():
            item = self.enc_layout.takeAt(0)
            widget = item.widget()
            if widget is not None:
                widget.deleteLater()
            elif item.layout() is not None:
                inner = item.layout()
                while inner.count():
                    sub = inner.takeAt(0)
                    if sub.widget() is not None:
                        sub.widget().deleteLater()
        self.enc_status = None
        self.enc_mk_chk = None

    def _enc_render(self):
        """(Re)draw the encryption controls to match the vault's current state."""
        self._enc_clear()
        lay = self.enc_layout
        if not secret_vault.crypto_available():
            lay.addWidget(label("Encryption backend unavailable ('cryptography' not installed).", "Muted"))
            return
        enabled = secret_vault.is_enabled()
        self.enc_status = label("", "Muted")
        set_status(self.enc_status,
                   "ON — credentials are encrypted at rest." if enabled else
                   "OFF — credentials are stored obfuscated (base64), not encrypted.",
                   "good" if enabled else "warn")
        lay.addWidget(self.enc_status)
        row = QHBoxLayout()
        if not enabled:
            btn = QPushButton("Enable encryption…"); btn.setObjectName("Primary")
            btn.clicked.connect(self._on_enc_enable)
            row.addWidget(btn); row.addStretch(1)
            lay.addLayout(row)
            return
        change = QPushButton("Change passphrase…"); change.clicked.connect(self._on_enc_change)
        disable = QPushButton("Disable…"); disable.setObjectName("Danger")
        disable.clicked.connect(self._on_enc_disable)
        row.addWidget(change); row.addWidget(disable); row.addStretch(1)
        lay.addLayout(row)
        self.enc_mk_chk = QCheckBox("Allow unattended (scheduled) backups on this computer")
        self.enc_mk_chk.setChecked(secret_vault.has_machine_key())
        self.enc_mk_chk.clicked.connect(self._on_enc_toggle_machine_key)
        lay.addWidget(self.enc_mk_chk)
        if not secret_vault.keychain_available():
            self.enc_mk_chk.setEnabled(False)
            lay.addWidget(label("(No OS keychain on this computer, so scheduled backups "
                                "can't run while encryption is on.)", "Muted"))

    def _enc_blocked(self, title):
        """Refuse to rewrite every stored secret while that would be unsafe."""
        if self._busy():
            _warn(self, title, "A backup or restore is running. Wait for it to finish, "
                               "then change encryption.")
            return True
        try:
            pending = profile_manager.migration_pending()
        except Exception:
            pending = False
        if pending:
            _error(self, title, "An earlier credential change did not finish. Close SUYB and "
                                "open it again so it can put your saved credentials back "
                                "first. Nothing was changed.")
            return True
        return False

    def _ask_new_passphrase(self, title):
        pw1 = _ask_text(self, title, "Choose a passphrase:")
        if not pw1:
            return None
        pw2 = _ask_text(self, title, "Re-enter to confirm:")
        if pw2 != pw1:
            _error(self, title, "Passphrases didn't match.")
            return None
        return pw1

    def _on_enc_enable(self):
        if self._enc_blocked("Enable encryption"):
            return
        pw = self._ask_new_passphrase("Enable encryption")
        if not pw:
            return
        store_mk = False
        if secret_vault.keychain_available():
            store_mk = _ask_yes_no(
                self, "Unattended backups?",
                "Allow scheduled (unattended) backups to run on THIS computer while "
                "encryption is on?\n\nThis caches a machine key in this computer's OS "
                "keychain (never on the portable drive). Choose No if you only ever "
                "back up manually.")
        try:
            profile_manager.enable_encryption(pw, store_machine_key=store_mk)
        except Exception as exc:
            _error(self, "Enable encryption", f"Failed: {exc}")
            self._enc_render()
            return
        _info(self, "Encryption enabled",
              "Saved credentials are now encrypted. You'll enter this passphrase each "
              "time SUYB starts.\n\nThere is NO recovery if you forget it.")
        self._enc_render()

    def _on_enc_change(self):
        if self._enc_blocked("Change passphrase"):
            return
        old = _ask_text(self, "Change passphrase", "Current passphrase:")
        if not old:
            return
        new = self._ask_new_passphrase("Change passphrase")
        if not new:
            return
        try:
            ok = profile_manager.change_encryption_passphrase(old, new)
        except Exception as exc:
            _error(self, "Change passphrase", f"Failed: {exc}")
            self._enc_render()
            return
        if not ok:
            _error(self, "Change passphrase", "Current passphrase was wrong.")
            return
        _info(self, "Passphrase changed", "Your passphrase has been updated.")
        self._enc_render()

    def _on_enc_disable(self):
        if self._enc_blocked("Disable encryption"):
            return
        if not _ask_yes_no(self, "Disable encryption",
                           "Turn OFF credential encryption? Saved credentials will return to "
                           "base64 obfuscation (not encrypted)."):
            return
        if not secret_vault.is_unlocked():
            pw = _ask_text(self, "Disable encryption", "Passphrase:")
            if not pw or not secret_vault.unlock(pw):
                _error(self, "Disable encryption", "Wrong passphrase.")
                return
        try:
            profile_manager.disable_encryption()
        except Exception as exc:
            _error(self, "Disable encryption", f"Failed: {exc}")
            self._enc_render()
            return
        _info(self, "Encryption disabled", "Credential encryption is now off.")
        self._enc_render()

    def _on_enc_toggle_machine_key(self, *_):
        chk = self.enc_mk_chk
        if chk is None:
            return
        if chk.isChecked():
            if secret_vault.store_machine_key_now():
                _info(self, "Unattended backups", "Scheduled backups can now run on this computer.")
            else:
                chk.setChecked(False)
                _error(self, "Unattended backups", "Couldn't store the machine key (no OS keychain?).")
        else:
            secret_vault.clear_machine_key()
            _info(self, "Unattended backups",
                  "Scheduled backups on this computer are now disabled while encryption is on.")

    # ── Automatic backups / app options ─────────────────────────────────
    def _sched_time_str(self):
        return self.global_sched_time.time().toString("HH:mm")

    def _on_schedule_time_edited(self):
        """A new time only takes effect by re-registering the daily task."""
        if self.global_sched_chk.isChecked() and self._sched_time_str() != self._applied_sched_time:
            self._on_global_schedule_toggle()

    def _on_global_schedule_toggle(self, *_):
        """One switch → an OS-level daily 'back up all blogs' task (headless)."""
        import os_schedule
        enabled = self.global_sched_chk.isChecked()
        # A headless run needs a machine key to decrypt credentials (SECAUDIT 037).
        if enabled and secret_vault.is_enabled() and not secret_vault.has_machine_key():
            if secret_vault.keychain_available() and secret_vault.is_unlocked():
                if _ask_yes_no(self, "Encryption is on",
                               "Credential encryption is on. Scheduled backups run while you're "
                               "away, so they need a machine key on this computer to decrypt "
                               "credentials.\n\nStore a machine key now so scheduled backups "
                               "can run?"):
                    secret_vault.store_machine_key_now()
                    self._enc_render()
                else:
                    _info(self, "Encryption is on",
                          "The schedule will be created, but scheduled runs will skip until you "
                          "store a machine key (Settings → Credential encryption).")
            else:
                _warn(self, "Encryption is on",
                      "Credential encryption is on and this computer has no OS keychain, so "
                      "scheduled backups can't decrypt credentials and will skip. Back up "
                      "manually, or disable encryption.")
        time_str = (self._sched_time_str() or "02:00").strip()
        ok, msg = os_schedule.set_global_schedule(enabled, time_str)
        _status(self.global_sched_status, ("✓ " if ok else "✗ ") + msg)
        if ok:
            self._applied_sched_time = time_str
        else:
            # snap the box back to what the OS really has
            self.global_sched_chk.setChecked(bool(os_schedule.schedule_state().get("enabled", False)))
        cfg = config_module.load()
        if not cfg.has_section("app"):
            cfg.add_section("app")
        cfg.set("app", "global_schedule_enabled", str(self.global_sched_chk.isChecked()).lower())
        cfg.set("app", "global_schedule_time", time_str)
        config_module.save(cfg)

    def _on_tray_toggle(self, *_):
        enabled = self.tray_chk.isChecked()
        cfg = config_module.load()
        if not cfg.has_section("app"):
            cfg.add_section("app")
        cfg.set("app", "tray_enabled", str(enabled).lower())
        config_module.save(cfg)
        hook = getattr(self.window_ref, "set_tray_enabled", None)
        if callable(hook):
            hook(enabled)

    def _on_startup_toggle(self, *_):
        enabled = self.startup_chk.isChecked()
        if sys.platform == "win32":
            self._set_windows_startup(enabled)
        else:
            self._set_linux_autostart(enabled)
        cfg = config_module.load()
        if not cfg.has_section("app"):
            cfg.add_section("app")
        cfg.set("app", "startup_enabled", str(enabled).lower())
        config_module.save(cfg)

    def _set_windows_startup(self, enabled):
        try:
            import winreg
            key_path = r"Software\Microsoft\Windows\CurrentVersion\Run"
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path, 0, winreg.KEY_SET_VALUE) as key:
                if enabled:
                    winreg.SetValueEx(key, "SmackUpYourBackup", 0, winreg.REG_SZ, f'"{sys.executable}"')
                else:
                    try:
                        winreg.DeleteValue(key, "SmackUpYourBackup")
                    except FileNotFoundError:
                        pass
        except Exception as exc:
            _error(self, "Startup error", f"Could not set startup entry:\n{exc}")

    @staticmethod
    def _set_linux_autostart(enabled):
        autostart_dir = os.path.expanduser("~/.config/autostart")
        desktop_path = os.path.join(autostart_dir, "smackupyourbackup.desktop")
        if enabled:
            os.makedirs(autostart_dir, exist_ok=True)
            content = ("[Desktop Entry]\nType=Application\nName=Smack Up Your Backup\n"
                       f"Exec={sys.executable}\nHidden=false\nNoDisplay=false\n"
                       "X-GNOME-Autostart-enabled=true\n")
            with open(desktop_path, "w") as f:
                f.write(content)
        else:
            try:
                os.unlink(desktop_path)
            except FileNotFoundError:
                pass

    # ── Hub / spoke ─────────────────────────────────────────────────────
    def _discover_from_hub(self):
        dlg = HubDiscoveryDialog(self, self._global_cloud())
        dlg.prefill(getattr(self.window_ref, "current_profile", None))
        dlg.exec()
        if dlg.created_count > 0:
            _status(self.disc_status, f"✓ Created {dlg.created_count} profile(s) from hub discovery.")
            reload = getattr(self.window_ref, "reload_profiles", None)
            if callable(reload):
                reload(self._profile_name)

    def _pull_cloud_config(self):
        name = self._profile_name
        if not name:
            _warn(self, "No site", "Choose a site first.")
            return
        profile = profile_manager.load_profile(name) or {}
        site_url = str(profile.get("site_url", "")).strip()
        admin_user = str(profile.get("snap_admin_user", "")).strip()
        admin_pass = str(profile.get("snap_admin_pass", "")).strip()
        if not site_url or not admin_user or not admin_pass:
            _warn(self, "Missing credentials",
                  "Fill in the site URL and admin sign-in first, then save the site.")
            return
        slug = profile.get("login_slug", "snap-in")
        _status(self.disc_status, "Connecting to blog…")

        def work():
            from hub_discovery import HubDiscovery
            disc = HubDiscovery(site_url, admin_user, admin_pass, login_slug=slug)
            try:
                return disc.fetch_suyb_data()
            finally:
                disc.close()

        run_in_thread(self._relay, work, self._apply_cloud_config,
                      lambda exc: _status(self.disc_status, f"Error: {exc}"))

    def _apply_cloud_config(self, data):
        cloud = (data or {}).get("cloud_config", {}) or {}
        provider = cloud.get("provider", "none")
        folder_id = cloud.get("folder_id", "")
        updated = []
        if provider and provider != "none":
            _set_combo_value(self.cloud_provider_combo, provider)
            self.method_buttons["cloud"].setChecked(True)
            self._on_method_change()
            updated.append(f"provider={provider}")
        if folder_id:
            self._edits["cloud_folder_id"].setText(folder_id)
            updated.append(f"folder={folder_id}")
        if updated:
            _status(self.disc_status, f"Pulled: {', '.join(updated)} — press Save site settings to keep it.")
        else:
            _status(self.disc_status, "No cloud configuration found on this blog.")

    # ── AI file matching ────────────────────────────────────────────────
    def _refresh_ai_status(self):
        """Loading the model is slow, so check once per session, off the main thread."""
        self._ai_checked = True
        _status(self.ai_status, "Checking…")

        def work():
            import ai_matcher
            return ai_matcher.status_string()

        run_in_thread(self._relay, work, lambda s: _status(self.ai_status, s),
                      lambda _e: _status(self.ai_status, "Not installed — pip install sentence-transformers"))

    def _install_ai(self):
        if getattr(sys, "frozen", False):
            python = shutil.which("python") or shutil.which("python3")
            if not python:
                _info(self, "Manual install required",
                      "SUYB is running as a compiled exe and can't run pip directly.\n\n"
                      "To enable AI file matching, open a terminal and run:\n\n"
                      "    pip install sentence-transformers\n\nThen restart SUYB.")
                return
        else:
            python = sys.executable
        _status(self.ai_status, "Installing — this may take a minute…")
        self.ai_btn.setEnabled(False)

        def work():
            try:
                result = subprocess.run([python, "-m", "pip", "install", "sentence-transformers"],
                                        capture_output=True, text=True, timeout=300)
            except subprocess.TimeoutExpired:
                return False, "Install timed out — try manually"
            if result.returncode == 0:
                return True, ""
            err = (result.stderr or result.stdout or "Unknown error").strip()[-200:]
            return False, f"Install failed: {err}"

        def done(result):
            self.ai_btn.setEnabled(True)
            ok, msg = result
            if ok:
                self._refresh_ai_status()
            else:
                _status(self.ai_status, msg)

        def failed(exc):
            self.ai_btn.setEnabled(True)
            _status(self.ai_status, f"Error: {exc}")

        run_in_thread(self._relay, work, done, failed)

    # ── Export / import ─────────────────────────────────────────────────
    def _export_settings(self):
        path, _ = QFileDialog.getSaveFileName(self, "Export settings", "suyb-settings-export.json",
                                              "JSON files (*.json)")
        if not path:
            return
        profiles = []
        for name in profile_manager.list_profiles():
            p = profile_manager.load_profile(name)
            if p:
                export_p = dict(p)
                # Plain passwords stay out; their stored (_enc) forms travel.
                export_p.pop("ftp_pass", None)
                export_p.pop("snap_admin_pass", None)
                profiles.append(export_p)
        cfg = config_module.load()
        global_cfg = {section: dict(cfg[section]) for section in cfg.sections()}
        bundle = {
            "export_version": 1,
            "app_version": BUILD_VERSION,
            "exported_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "global_config": global_cfg,
            "profiles": profiles,
        }
        try:
            with open(path, "w") as f:
                json.dump(bundle, f, indent=2)
        except Exception as exc:
            _error(self, "Export failed", str(exc))
            return
        _info(self, "Exported",
              f"Settings exported to:\n{path}\n\n{len(profiles)} profile(s) saved.\n"
              "Passwords are stored in their saved (encoded or encrypted) form. Each site's "
              "backup key is in plain text — keep this file private.")

    def _import_settings(self):
        if self._busy():
            _warn(self, "Import settings", "A backup or restore is running. Wait for it to finish first.")
            return
        path, _ = QFileDialog.getOpenFileName(self, "Import settings", "",
                                              "JSON files (*.json);;All files (*.*)")
        if not path:
            return
        try:
            with open(path) as f:
                bundle = json.load(f)
        except Exception as exc:
            _error(self, "Import failed", f"Could not read file:\n{exc}")
            return
        if not isinstance(bundle, dict) or "profiles" not in bundle:
            _error(self, "Invalid file", "This doesn't look like a SUYB settings export.")
            return
        cfg = config_module.load()
        for section, values in (bundle.get("global_config") or {}).items():
            if not cfg.has_section(section):
                cfg.add_section(section)
            for key, val in (values or {}).items():
                cfg.set(section, key, str(val))
        config_module.save(cfg)
        imported = 0
        failed = []
        for p in bundle.get("profiles", []):
            if not isinstance(p, dict) or not p.get("name"):
                continue
            # Vault-aware open: handles both base64 and enc1: exports.
            p["ftp_pass"] = profile_manager._open_pw(p.get("ftp_pass_enc", ""))
            p["snap_admin_pass"] = profile_manager._open_pw(p.get("snap_admin_pass_enc", ""))
            try:
                profile_manager.save_profile(p)
                imported += 1
            except Exception as exc:
                failed.append(f"{p.get('name')}: {exc}")
        reload = getattr(self.window_ref, "reload_profiles", None)
        if callable(reload):
            reload(self._profile_name)
        self.refresh()
        msg = f"Imported {imported} profile(s) and global settings from:\n{path}"
        if failed:
            _warn(self, "Imported with problems", msg + "\n\nNOT imported:\n" + "\n".join(failed))
        else:
            _info(self, "Imported", msg)


HELP_TOPICS = [
    ("Settings page", """
This site's admin sign-in — the blog's admin username, password and login slug. Test Login checks the backup key if the site has one (Connection page), otherwise the admin sign-in. Test FTP connects to the FTP or SFTP server with what is typed now, before you save.

Backup method — FTP, Cloud, or Local. This is saved per site. Press Save site settings to keep changes; it only changes the fields on this page and never touches the site name, address, backup key or working folder.

FTP setup — protocol, host, port, username, password, remote folder. "Verify certificate" is off by default because shared hosting servers present certificates for the server's own name, not your domain — the same as clicking Trust in FileZilla. SUYB still remembers the certificate the first time and stops if it ever changes.

Defaults for every site — pacing delay, batch size, and the cloud account every site uses unless a site sets its own. Press Save defaults.

Automatic backups — one switch backs up every blog once a day at the time you set, even when SUYB is closed. "Minimize to system tray" keeps SUYB running when you close the window. "Launch SUYB when Windows starts" opens it when you sign in to Windows.
"""),
    ("Credential encryption", """
By default SUYB stores saved passwords obfuscated (base64), which is NOT encryption — anyone who can read the SUYB folder (a lost thumb drive, a synced copy, a shared PC) can recover them. Turn on Credential encryption in Settings to fix that.

Enable — Settings → Credential encryption → Enable encryption. Choose a passphrase (entered twice). SUYB encrypts your FTP password, admin password, API key, and cloud tokens with a key made from that passphrase. The passphrase is never stored.

Unlocking — once encryption is on, SUYB asks for the passphrase each time it starts. There is NO recovery if you forget it: your only option is to delete "vault.meta" and re-enter your credentials from scratch.

Scheduled (unattended) backups — a scheduled run has nobody to type the passphrase. If you want scheduled backups while encryption is on, tick "Allow unattended (scheduled) backups on this computer". That keeps the key in THIS computer's OS keychain (never on the portable drive), so a stolen drive still can't decrypt. On a computer with no keychain, scheduled backups are skipped while encryption is on.

Change passphrase / Disable — both are in the same card. Disabling returns credentials to the old base64 storage. These changes rewrite every saved credential, so they wait until any running backup or restore has finished.
"""),
    ("Cloud setup", """
SUYB supports Google Drive.

Google Drive uses OAuth. Download an OAuth client secret JSON from Google Cloud Console → APIs & Services → Credentials → Create OAuth 2.0 Client ID → Desktop app. Point SUYB at it in Settings → Defaults for every site → Credentials JSON, press Authenticate with Google (a browser opens for a one-time consent click), then Save defaults. After that the sign-in refreshes by itself.

Folder ID is the Google Drive folder (from its web address) where backups go. Test cloud checks the account and fills in the folder it found.

Saved credentials — register a credentials file once under a name with Manage Library…, then pick it by name for any site or sync job.
"""),
]

# ===== SNAPSMACK EOF =====
