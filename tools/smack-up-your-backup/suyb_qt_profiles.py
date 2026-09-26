"""SMACK UP YOUR BACKUP — add, edit and delete site connections (Qt).

Qt port of the Tk window's site-profile dialogs in main.py:
    ProfileDialog        the "New / Edit site" form (Tk ProfileDialog)
    SetupWizard          the first-run step-by-step setup (Tk SetupWizard)
    HubDiscoveryDialog   "add every site from my hub" (Tk HubDiscoveryDialog)
    delete_profile_confirmed(parent, name)   Tk App._del_profile's warning + delete

Every dialog is modal. After exec() returns QDialog.Accepted, ProfileDialog and
SetupWizard hold the finished profile dict in `.result` (None otherwise); the
caller saves it with profile_manager.save_profile(), exactly like the Tk App.

Network tests run on worker threads (suyb_qt_common.run_in_thread). Widgets are
only touched on the main thread, through a Relay.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os

from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QDialog, QFileDialog, QFormLayout, QHBoxLayout,
    QLineEdit, QMessageBox, QPushButton, QScrollArea, QStackedWidget, QVBoxLayout,
    QWidget,
)

import config as config_module
import ftps_pins
import profile_manager
from suyb_qt_common import Relay, card, label, run_in_thread, set_status


# Same table as main.py SPEED_TIERS (copied, not imported: main.py pulls in Tk).
# The value is the per-file pacing delay stored in profile["pacing_delay"].
SPEED_TIERS = [
    ("Full Send",       "0.0"),
    ("Fast Lane",       "0.25"),
    ("Steady",          "0.5"),
    ("Easy Does It",    "1.0"),
    ("Sunday Driver",   "2.0"),
    ("Pump da Brakes",  "5.0"),
    ("Grandma's Pace",  "10.0"),
    ("Geological Time", "30.0"),
]
SPEED_DEFAULT = "0.0"

# Stored value -> what the owner sees. Tk only listed ftp/sftp, but a new
# profile's template value is "http", which Tk showed as-is; it is listed here
# so it can be seen and picked again.
TRANSPORTS = [
    ("http", "HTTP — through the site itself (no FTP needed)"),
    ("ftp",  "FTP"),
    ("sftp", "SFTP"),
]
CLOUD_PROVIDERS = [("google_drive", "Google Drive"), ("none", "None")]

INT_KEYS = ("ftp_port", "pacing_delay", "batch_size")
CLIENT_ID_HINT = ("That's the client ID — Browse to the downloaded "
                  "client_secret_*.json instead.")
BUTTON_HEIGHT = 40


# ── small helpers ──────────────────────────────────────────────────────────

def _error(parent, title, text):
    QMessageBox.critical(parent, title, text)


def _warning(parent, title, text):
    QMessageBox.warning(parent, title, text)


def _info(parent, title, text):
    QMessageBox.information(parent, title, text)


def _button(text, slot, name=""):
    button = QPushButton(text)
    if name:
        button.setObjectName(name)
    button.setMinimumHeight(BUTTON_HEIGHT)
    button.clicked.connect(lambda _checked=False: slot())
    return button


def _combo(pairs, current):
    """Combo whose itemData is the stored value. An unknown stored value is
    kept (added to the list) so opening and saving never changes it."""
    combo = QComboBox()
    combo.setMinimumHeight(BUTTON_HEIGHT)
    for value, text in pairs:
        combo.addItem(text, value)
    index = combo.findData(current)
    if index < 0:
        combo.addItem(str(current), current)
        index = combo.count() - 1
    combo.setCurrentIndex(index)
    return combo


def _kind(message):
    text = str(message)
    if text.startswith("✓") or text.startswith("Done"):
        return "good"
    if text.startswith("✗") or text.startswith("Error"):
        return "bad"
    return "warn"


def _pick_folder(parent, title, start=""):
    return QFileDialog.getExistingDirectory(parent, title, start or "")


def _pick_json(parent, title, start=""):
    path, _ = QFileDialog.getOpenFileName(
        parent, title, start or "", "JSON files (*.json);;All files (*.*)")
    return path


def _offer_new_certificate(parent, exc, port):
    """An FTPS test stopped because the server certificate changed. Ask; on yes
    remember the new certificate (ftps_pins.accept_change). Returns True if accepted."""
    box = QMessageBox(parent)
    box.setWindowTitle("FTPS certificate changed")
    box.setIcon(QMessageBox.Warning)
    box.setText(f"The FTPS certificate for {exc.host} changed.")
    box.setInformativeText(
        "SUYB stopped before sending the password. Accept this only if you "
        "changed the server certificate or confirmed the change with your host.\n\n"
        f"Why it stopped: {exc.why}\n\n"
        f"Remembered:\n{exc.old_fp}\n\n"
        f"Offered now:\n{exc.new_fp}")
    accept = box.addButton("Accept new certificate and test again", QMessageBox.AcceptRole)
    accept.setObjectName("Danger")
    stop = box.addButton("Stop — don't trust it", QMessageBox.RejectRole)
    box.setDefaultButton(stop); box.setEscapeButton(stop)
    box.exec()
    if box.clickedButton() is not accept:
        return False
    ftps_pins.accept_change(exc.host, int(port), exc.new_fp)
    return True


def _test_ftp_work(snapshot):
    """Connect and disconnect with the transport the snapshot names. Raises on failure."""
    import transport
    client = transport.make_client(snapshot, transfer_delay=0)
    client.connect()
    client.disconnect()


class _ModalBase(QDialog):
    """Shared plumbing: a Relay for worker results and a guard so a result that
    arrives after the dialog closed is dropped instead of touching dead widgets."""

    def __init__(self, parent, title):
        super().__init__(parent)
        self.setWindowTitle(title)
        self.setModal(True)
        self._alive = True
        self._relay = Relay(self)

    def done(self, code):
        self._alive = False
        super().done(code)

    def _run(self, work, on_done, on_error):
        def ok(value):
            if self._alive:
                on_done(value)

        def bad(exc):
            if self._alive:
                on_error(exc)
        return run_in_thread(self._relay, work, ok, bad)


# ── Profile editor ─────────────────────────────────────────────────────────

class ProfileDialog(_ModalBase):
    """New / Edit site form. `.result` is the full profile dict after Accept."""

    def __init__(self, parent=None, profile=None, title="New site"):
        super().__init__(parent, title)
        self.result = None
        self.hub_created_count = 0     # sites added through "Add every site from a hub"
        self._is_new = not profile
        self._data = dict(profile_manager.new_profile_template())
        if profile:
            self._data.update(profile)
        self._edits = {}
        self._checks = {}
        self._combos = {}
        self._test_buttons = []
        self._build()
        self.resize(760, 860)

    # form builders
    def _field(self, form, text, key, password=False):
        edit = QLineEdit(str(self._data.get(key, "")))
        edit.setMinimumHeight(BUTTON_HEIGHT)
        if password:
            edit.setEchoMode(QLineEdit.Password)
        self._edits[key] = edit
        form.addRow(label(text, "Muted"), edit)
        return edit

    def _field_browse(self, form, text, key, browse):
        edit = QLineEdit(str(self._data.get(key, "")))
        edit.setMinimumHeight(BUTTON_HEIGHT)
        self._edits[key] = edit
        row = QHBoxLayout(); row.addWidget(edit, 1); row.addWidget(_button("Browse…", browse))
        form.addRow(label(text, "Muted"), row)
        return edit

    def _speed(self, form, text, key):
        try:
            current = float(self._data.get(key, SPEED_DEFAULT) or SPEED_DEFAULT)
        except (ValueError, TypeError):
            current = float(SPEED_DEFAULT)
        value = next((v for _l, v in SPEED_TIERS if float(v) == current), SPEED_TIERS[0][1])
        combo = QComboBox(); combo.setMinimumHeight(BUTTON_HEIGHT)
        for lbl, v in SPEED_TIERS:
            combo.addItem(lbl, v)
        combo.setCurrentIndex(combo.findData(value))
        self._combos[key] = combo
        form.addRow(label(text, "Muted"), combo)

    @staticmethod
    def _form(layout):
        form = QFormLayout(); form.setSpacing(8)
        form.setFieldGrowthPolicy(QFormLayout.AllNonFixedFieldsGrow)
        layout.addLayout(form)
        return form

    def _build(self):
        outer = QVBoxLayout(self); outer.setContentsMargins(18, 18, 18, 18); outer.setSpacing(12)
        scroll = QScrollArea(); scroll.setWidgetResizable(True)
        host = QWidget(); col = QVBoxLayout(host); col.setSpacing(14)

        frame, lay = card("Site")
        form = self._form(lay)
        self._field(form, "Blog name", "name")
        self._field(form, "Site URL", "site_url")
        self._field(form, "API key", "api_key", password=True)
        if self._is_new:
            lay.addWidget(label("Running a hub? It can add every site in your network at once.", "Muted"))
            self._hub_btn = _button("Add every site from a hub…", self._open_hub)
            lay.addWidget(self._hub_btn)
        col.addWidget(frame)

        frame, lay = card("FTP / SFTP")
        form = self._form(lay)
        self._transport = _combo(TRANSPORTS, str(self._data.get("transport", "ftp") or "ftp"))
        self._combos["transport"] = self._transport
        form.addRow(label("Protocol", "Muted"), self._transport)
        self._field(form, "Host", "ftp_host")
        self._field(form, "Port", "ftp_port")
        self._field(form, "Username", "ftp_user")
        self._field(form, "Password", "ftp_pass", password=True)
        self._field(form, "Remote directory", "ftp_remote_dir")
        self._tls = QCheckBox("Use FTP_TLS")
        self._tls.setChecked(bool(self._data.get("ftp_ssl", True)))
        self._checks["ftp_ssl"] = self._tls
        form.addRow("", self._tls)
        col.addWidget(frame)

        frame, lay = card("SnapSmack Admin")
        form = self._form(lay)
        self._field(form, "Admin username", "snap_admin_user")
        self._field(form, "Admin password", "snap_admin_pass", password=True)
        col.addWidget(frame)

        frame, lay = card("Cloud")
        form = self._form(lay)
        self._combos["cloud_provider"] = _combo(
            CLOUD_PROVIDERS, str(self._data.get("cloud_provider", "none") or "none"))
        form.addRow(label("Provider", "Muted"), self._combos["cloud_provider"])
        creds = self._field_browse(form, "Creds override (optional)",
                                   "cloud_credentials_file", self._browse_credentials)
        self._auth_btn = _button("Authenticate with Google", self._authenticate_cloud)
        form.addRow("", self._auth_btn)
        self._auth_btn.hide()            # shown only for an OAuth client secret
        self._field(form, "Cloud folder ID", "cloud_folder_id")
        col.addWidget(frame)

        frame, lay = card("Backup")
        form = self._form(lay)
        self._field_browse(form, "Local backup directory", "backup_dir", self._browse_backup_dir)
        self._speed(form, "Transfer speed", "pacing_delay")
        self._field(form, "Batch size (0=unlimited)", "batch_size")
        col.addWidget(frame)
        col.addStretch(1)

        scroll.setWidget(host)
        outer.addWidget(scroll, 1)

        tests = QHBoxLayout()
        self._login_btn = _button("Test Login", self._test_login)
        self._conn_btn = _button("Test FTP/SFTP", self._test_conn)
        self._cloud_btn = _button("Test Cloud", self._test_cloud)
        self._test_buttons = [self._login_btn, self._conn_btn, self._cloud_btn, self._auth_btn]
        for b in (self._login_btn, self._conn_btn, self._cloud_btn):
            tests.addWidget(b)
        tests.addStretch(1)
        outer.addLayout(tests)
        self.status = label("", "Muted")
        outer.addWidget(self.status)

        buttons = QHBoxLayout(); buttons.addStretch(1)
        self._cancel_btn = _button("Cancel", self.reject)
        self._save_btn = _button("Save", self._save, "Primary")
        self._save_btn.setDefault(True)
        buttons.addWidget(self._cancel_btn); buttons.addWidget(self._save_btn)
        outer.addLayout(buttons)

        self._transport.currentIndexChanged.connect(lambda _i: self._on_transport_change())
        creds.textChanged.connect(lambda _t: self._refresh_cloud_auth())
        self._on_transport_change()
        self._refresh_cloud_auth()

    # values
    def _values(self):
        """Live form values, same types Tk's variables returned (strings, bool
        for the TLS box, the tier's delay string for the speed picker)."""
        values = {key: edit.text() for key, edit in self._edits.items()}
        values.update({key: box.isChecked() for key, box in self._checks.items()})
        values.update({key: combo.currentData() for key, combo in self._combos.items()})
        return values

    def _snapshot(self):
        data = dict(self._data)
        data.update(self._values())
        return data

    def _say(self, message, kind=None):
        set_status(self.status, message, kind or _kind(message))

    def _set_testing(self, busy):
        for b in self._test_buttons:
            b.setEnabled(not busy)

    # protocol
    def _on_transport_change(self):
        is_sftp = self._transport.currentData() == "sftp"
        self._tls.setEnabled(not is_sftp)
        port = self._edits["ftp_port"]
        cur = port.text().strip()
        if is_sftp and cur in ("", "21"):
            port.setText("22")
        elif not is_sftp and cur in ("", "22"):
            port.setText("21")

    # browse
    def _browse_credentials(self):
        path = _pick_json(self, "Select credentials JSON", self._edits["cloud_credentials_file"].text())
        if path:
            self._edits["cloud_credentials_file"].setText(path)

    def _browse_backup_dir(self):
        path = _pick_folder(self, "Choose local backup folder", self._edits["backup_dir"].text())
        if path:
            self._edits["backup_dir"].setText(path)

    # hub
    def _open_hub(self):
        dlg = HubDiscoveryDialog(self, app=self.parent())
        dlg.exec()
        if dlg.created_count > 0:
            self.hub_created_count += dlg.created_count
            self._say(f"✓ Added {self.hub_created_count} site(s) from your hub. "
                      "You can close this window.")

    # tests
    def _finish(self, message, kind=None):
        self._set_testing(False)
        self._say(message, kind)

    def _test_login(self):
        p = self._snapshot()
        url = str(p.get("site_url", "")).strip().rstrip("/")
        user = str(p.get("snap_admin_user", "")).strip()
        pw = str(p.get("snap_admin_pass", ""))
        api_key = str(p.get("api_key", "")).strip()
        slug = str(p.get("login_slug", "snap-in")).strip().strip("/") or "snap-in"
        if not url or (not api_key and (not user or not pw)):
            self._say("Need URL + API key (or admin username & password)", "warn")
            return
        self._set_testing(True)
        self._say("Testing login…", "warn")

        def work():
            import backup_engine
            sess = backup_engine.SnapSmackSession(url, api_key=api_key, login_slug=slug)
            if api_key:
                # The Bearer key is valid on the API endpoints, not the admin
                # page; test the real path the backup uses (see Tk _test_login).
                r = sess.session.get(f"{url}/suyb-export.php?type=schema",
                                     timeout=15, allow_redirects=False, stream=True)
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

        self._run(work, self._finish, lambda e: self._finish(f"✗ {e}"))

    def _test_conn(self):
        p = self._snapshot()
        host = str(p.get("ftp_host", "")).strip()
        if not host:
            self._say("Fill in the host first", "warn")
            return
        is_sftp = str(p.get("transport", "ftp")).lower() == "sftp"
        proto = "SFTP" if is_sftp else "FTP"
        try:
            port = int(p.get("ftp_port") or (22 if is_sftp else 21))
        except (ValueError, TypeError):
            port = 22 if is_sftp else 21
        self._set_testing(True)
        self._say(f"Connecting to {host}:{port} ({proto})…", "warn")

        def failed(exc):
            if isinstance(exc, ftps_pins.CertificateChanged):
                self._finish("✗ The server's FTPS certificate changed — nothing was sent.")
                if _offer_new_certificate(self, exc, port):
                    self._test_conn()
                return
            self._finish(f"✗ {host}:{port} — {exc}")

        self._run(lambda: _test_ftp_work(p),
                  lambda _r: self._finish(f"✓ Connected to {host} ({proto})"), failed)

    def _test_cloud(self):
        p = self._snapshot()
        creds = str(p.get("cloud_credentials_file", "")).strip()
        if not creds:
            self._say("Set the cloud credentials JSON first", "warn")
            return
        if not os.path.isfile(creds):
            self._say(CLIENT_ID_HINT if creds.endswith("apps.googleusercontent.com")
                      else "Credentials file not found", "warn")
            return
        self._set_testing(True)
        self._say("Testing cloud…", "warn")

        def work():
            import cloud_client
            client = cloud_client.get_cloud_client(p)
            if not client:
                return False, "Pick a provider + set credentials first", None
            if not hasattr(client, "test_connection"):
                return False, f"Test not supported for provider '{p.get('cloud_provider', '')}'", None
            ok, msg = client.test_connection()
            folder = None
            if ok and hasattr(client, "resolved_folder_id"):
                try:
                    folder = client.resolved_folder_id()
                except Exception:
                    folder = None
            return ok, msg, folder

        def done(result):
            ok, msg, folder = result
            self._finish(msg, "good" if ok else "bad")
            if folder:
                self._edits["cloud_folder_id"].setText(folder)

        self._run(work, done, lambda e: self._finish(f"✗ {e}"))

    def _refresh_cloud_auth(self):
        import cloud_client
        path = self._edits["cloud_credentials_file"].text().strip()
        if path and cloud_client._is_oauth_client_secret(path):
            self._auth_btn.show()
            return
        self._auth_btn.hide()
        if path and not os.path.isfile(path) and path.endswith("apps.googleusercontent.com"):
            self._say(CLIENT_ID_HINT, "warn")

    def _authenticate_cloud(self):
        import cloud_client
        path = self._edits["cloud_credentials_file"].text().strip()
        if not path:
            self._say("Set the cloud credentials JSON first", "warn")
            return
        if not os.path.isfile(path):
            self._say(CLIENT_ID_HINT if path.endswith("apps.googleusercontent.com")
                      else "Credentials file not found", "warn")
            return
        if not cloud_client._is_oauth_client_secret(path):
            self._say("Not an OAuth client secret — service-account keys don't need this.", "warn")
            return
        self._set_testing(True)
        self._say("Opening browser for Google login…", "warn")
        self._run(lambda: cloud_client.authenticate_oauth(path),
                  lambda r: self._finish(r[1], "good" if r[0] else "bad"),
                  lambda e: self._finish(f"✗ {e}"))

    # save
    def _save(self):
        name = self._edits["name"].text().strip()
        if not name:
            _error(self, "Required", "Blog name is required.")
            return
        if not self._edits["site_url"].text().strip():
            _error(self, "Required", "Site URL is required.")
            return
        data = dict(self._data)
        for key, val in self._values().items():
            if key in INT_KEYS:       # Tk: keep the stored value's type when it converts
                try:
                    val = type(self._data.get(key, 0))(val)
                except (ValueError, TypeError):
                    pass
            data[key] = val
        self.result = data
        self.accept()


# ── First-run wizard ───────────────────────────────────────────────────────

class SetupWizard(_ModalBase):
    """Step-by-step first profile. `.result` is the profile dict after Finish."""

    STEPS = ["Welcome", "Blog Details", "Admin Login", "FTP Setup",
             "Backup Destination", "Ready!"]

    def __init__(self, parent=None):
        super().__init__(parent, "Welcome to Smack Up Your Backup")
        self.result = None
        self._step = 0
        self._data = profile_manager.new_profile_template()
        self._edits = {}
        self._build()
        self.resize(720, 640)
        self._show_step(0)

    def _page(self, heading, sub=""):
        page = QWidget(); lay = QVBoxLayout(page); lay.setSpacing(10)
        lay.addWidget(label(heading, "PageTitle"))
        if sub:
            lay.addWidget(label(sub, "Muted"))
        self._stack.addWidget(page)
        return lay

    def _form(self, lay):
        form = QFormLayout(); form.setSpacing(8)
        form.setFieldGrowthPolicy(QFormLayout.AllNonFixedFieldsGrow)
        lay.addLayout(form)
        return form

    def _field(self, form, text, key, password=False):
        edit = QLineEdit(str(self._data.get(key, "")))
        edit.setMinimumHeight(BUTTON_HEIGHT)
        if password:
            edit.setEchoMode(QLineEdit.Password)
        self._edits[key] = edit
        form.addRow(label(text, "Muted"), edit)
        return edit

    def _build(self):
        outer = QVBoxLayout(self); outer.setContentsMargins(26, 20, 26, 20); outer.setSpacing(12)
        self._dots = QHBoxLayout(); self._dot_labels = []
        for _name in self.STEPS:
            dot = label("●", "Muted"); dot.setWordWrap(False)
            self._dot_labels.append(dot); self._dots.addWidget(dot)
        self._dots.addStretch(1)
        outer.addLayout(self._dots)
        self._stack = QStackedWidget()
        outer.addWidget(self._stack, 1)

        # 0 Welcome
        lay = self._page("Welcome to Smack Up Your Backup",
                         "This wizard will walk you through connecting to your SnapSmack blog "
                         "and setting up your first backup profile.\n\n"
                         "Here's what SUYB does for you:")
        frame, flay = card("What it does")
        for icon, text in [
            ("📦", "Downloads your blog's recovery kit, database, and media files"),
            ("🔄", "Differential backups — only grabs what changed since last time"),
            ("☁️", "Optionally uploads to Google Drive"),
            ("🔍", "Audit mode checks your server for missing or orphaned files"),
            ("⏪", "Restore mode puts everything back if disaster strikes"),
        ]:
            flay.addWidget(label(f"{icon}   {text}"))
        lay.addWidget(frame); lay.addStretch(1)

        # 1 Blog details
        lay = self._page("Blog Details",
                         "Enter your blog's name and URL. The name is just a label — "
                         "pick whatever helps you identify this site.")
        form = self._form(lay)
        self._field(form, "Blog name", "name")
        self._field(form, "Site URL", "site_url")
        self._field(form, "API key", "api_key", password=True)
        lay.addStretch(1)

        # 2 Admin login
        lay = self._page("SnapSmack Admin Login",
                         "SUYB logs into your blog's admin panel to download the recovery kit "
                         "and SQL backups. Use the same credentials you log in with.")
        form = self._form(lay)
        self._field(form, "Admin username", "snap_admin_user")
        self._field(form, "Admin password", "snap_admin_pass", password=True)
        self._field(form, "Login slug", "login_slug")
        self._admin_btn = _button("Test Connection", self._test_admin)
        row = QHBoxLayout(); row.addWidget(self._admin_btn); row.addStretch(1)
        lay.addLayout(row)
        self._admin_status = label("", "Muted"); lay.addWidget(self._admin_status)
        lay.addStretch(1)

        # 3 FTP
        lay = self._page("FTP Connection",
                         "SUYB uses FTP to download and upload media files. "
                         "Your web host provides these credentials.")
        form = self._form(lay)
        self._field(form, "FTP host", "ftp_host")
        self._field(form, "Port", "ftp_port")
        self._field(form, "Username", "ftp_user")
        self._field(form, "Password", "ftp_pass", password=True)
        self._field(form, "Remote directory", "ftp_remote_dir")
        self._ssl = QCheckBox("Use FTP_TLS (recommended)"); self._ssl.setChecked(True)
        lay.addWidget(self._ssl)
        self._ftp_btn = _button("Test FTP", self._test_ftp)
        row = QHBoxLayout(); row.addWidget(self._ftp_btn); row.addStretch(1)
        lay.addLayout(row)
        self._ftp_status = label("", "Muted"); lay.addWidget(self._ftp_status)
        lay.addStretch(1)

        # 4 Backup destination
        lay = self._page("Backup Destination",
                         "Choose where to store backup files on this computer. "
                         "Cloud upload is optional — you can configure it later in Settings.")
        form = self._form(lay)
        self._field(form, "Local folder", "backup_dir")
        row = QHBoxLayout(); row.addWidget(_button("Browse…", self._browse_dir)); row.addStretch(1)
        lay.addLayout(row); lay.addStretch(1)

        # 5 Ready
        lay = self._page("You're all set!",
                         "Your profile is ready. Click Finish to save it and "
                         "jump to the Backup tab where you can run your first backup.")
        frame, flay = card("Summary")
        self._summary = label(""); flay.addWidget(self._summary)
        lay.addWidget(frame)
        lay.addWidget(label("Quick tour:", "CardTitle"))
        for tab, desc in [
            ("Backup", "Run backups — differential or full, one blog or all at once"),
            ("Restore", "Upload files back to your server from a backup package"),
            ("Manage", "View, sort, search, restore and delete your cloud backups"),
            ("Audit", "Scan your server for missing, orphaned, or mismatched files"),
            ("Settings", "Manage profiles, cloud config, and global defaults"),
        ]:
            lay.addWidget(label(f"{tab}:  {desc}", "Muted"))
        lay.addStretch(1)

        nav = QHBoxLayout()
        self._back_btn = _button("← Back", self._back)
        self._skip_btn = _button("Skip Setup", self.reject)
        self._next_btn = _button("Next →", self._next, "Primary")
        self._next_btn.setDefault(True)
        nav.addWidget(self._back_btn); nav.addWidget(self._skip_btn)
        nav.addStretch(1); nav.addWidget(self._next_btn)
        outer.addLayout(nav)

    # navigation
    def _show_step(self, idx):
        self._step = idx
        self._stack.setCurrentIndex(idx)
        for i, (dot, name) in enumerate(zip(self._dot_labels, self.STEPS)):
            dot.setText(f"● {name}" if i == idx else "●")
            dot.setObjectName("Eyebrow" if i == idx else ("CardTitle" if i < idx else "Muted"))
            dot.style().unpolish(dot); dot.style().polish(dot)
        self._back_btn.setEnabled(idx > 0)
        self._next_btn.setText("Finish ✓" if idx == len(self.STEPS) - 1 else "Next →")

    def _collect(self):
        for key, edit in self._edits.items():
            val = edit.text()
            if key in INT_KEYS:
                try:
                    val = int(val)
                except ValueError:
                    pass
            self._data[key] = val
        self._data["ftp_ssl"] = bool(self._ssl.isChecked())

    def _next(self):
        self._collect()
        if self._step == 1 and not str(self._data.get("name", "")).strip():
            _warning(self, "Blog name required", "Enter a name for this blog profile.")
            return
        if self._step == 4 and not str(self._data.get("backup_dir", "")).strip():
            _warning(self, "Folder required", "Pick a local folder for backup storage.")
            return
        last = len(self.STEPS) - 1
        if self._step < last - 1:
            self._show_step(self._step + 1)
        elif self._step == last - 1:
            d = self._data
            self._summary.setText(
                f"Blog:   {d.get('name', '')}\n"
                f"URL:    {d.get('site_url', '')}\n"
                f"FTP:    {d.get('ftp_user', '')}@{d.get('ftp_host', '')}:{d.get('ftp_port', 21)}\n"
                f"Folder: {d.get('backup_dir', '')}")
            self._show_step(self._step + 1)
        else:
            self.result = dict(self._data)
            self.accept()

    def _back(self):
        if self._step > 0:
            self._collect()
            self._show_step(self._step - 1)

    # actions
    def _test_admin(self):
        self._collect()
        url = str(self._data.get("site_url", "")).rstrip("/")
        slug = (str(self._data.get("login_slug", "snap-in") or "snap-in")).strip().strip("/") or "snap-in"
        self._admin_btn.setEnabled(False)
        set_status(self._admin_status, "Testing…", "warn")

        def work():
            import requests
            r = requests.get(f"{url}/{slug}", timeout=10, allow_redirects=False)
            return "✓ Blog is reachable" if r.status_code < 400 else f"⚠ HTTP {r.status_code}"

        def finish(msg):
            self._admin_btn.setEnabled(True)
            set_status(self._admin_status, msg, _kind(msg))
        self._run(work, finish, lambda e: finish(f"✗ {e}"))

    def _test_ftp(self):
        self._collect()
        # This step is the FTP step, so the test dials FTP even though a new
        # profile's saved transport is the template's "http".
        snap = dict(self._data); snap["transport"] = "ftp"
        try:
            port = int(snap.get("ftp_port") or 21)
        except (ValueError, TypeError):
            port = 21
        self._ftp_btn.setEnabled(False)
        set_status(self._ftp_status, "Connecting…", "warn")

        def finish(msg):
            self._ftp_btn.setEnabled(True)
            set_status(self._ftp_status, msg, _kind(msg))

        def failed(exc):
            if isinstance(exc, ftps_pins.CertificateChanged):
                finish("✗ The server's FTPS certificate changed — nothing was sent.")
                if _offer_new_certificate(self, exc, port):
                    self._test_ftp()
                return
            finish(f"✗ {exc}")

        self._run(lambda: _test_ftp_work(snap),
                  lambda _r: finish("✓ FTP connected successfully"), failed)

    def _browse_dir(self):
        path = _pick_folder(self, "Choose backup folder", self._edits["backup_dir"].text())
        if path:
            self._edits["backup_dir"].setText(path)


# ── Hub discovery ──────────────────────────────────────────────────────────

def _app_current_profile(app):
    if app is None:
        return None
    value = getattr(app, "current_profile", None)
    if callable(value):                         # Tk App.current_profile()
        value = value()
    if value is None:
        value = getattr(app, "_current_profile", None)
    return value if isinstance(value, dict) else None


def _app_global_cloud(app):
    for attr in ("global_cloud", "global_cloud_config"):
        fn = getattr(app, attr, None) if app is not None else None
        if callable(fn):
            try:
                return dict(fn())
            except Exception:
                pass
    cfg = config_module.load()
    return {"cloud_provider": cfg.get("cloud", "provider", fallback="none"),
            "cloud_credentials_file": cfg.get("cloud", "credentials_file", fallback=""),
            "cloud_folder_id": cfg.get("cloud", "folder_id", fallback="")}


class HubDiscoveryDialog(_ModalBase):
    """Connect to a hub, create a profile for it and every spoke.

    `app` is the main window (Tk App or Qt SuybWindow); defaults to `parent`.
    It supplies the current site (fallback hub URL/key) and the global cloud
    config. After the dialog closes, `.created_count` is the number of new profiles.
    """

    def __init__(self, parent=None, app=None, title="Discover from Hub"):
        super().__init__(parent, title)
        self._app = app if app is not None else parent
        self.created_count = 0
        self._build()
        self.resize(620, 380)

    def _build(self):
        outer = QVBoxLayout(self); outer.setContentsMargins(22, 20, 22, 20); outer.setSpacing(12)
        frame, lay = card("Hub Connection",
                          "Enter the hub blog's URL and its API key.\n"
                          "SUYB pulls every spoke, fills in each one's key, and\n"
                          "points them all at your Global Cloud Config.")
        form = QFormLayout(); form.setSpacing(8)
        form.setFieldGrowthPolicy(QFormLayout.AllNonFixedFieldsGrow)
        lay.addLayout(form)

        hub_url = config_module.shared_cred("hub_url")
        hub_key = config_module.shared_cred("hub_key")
        cp = _app_current_profile(self._app)
        if not hub_url and cp:
            hub_url = cp.get("site_url", "")
        if not hub_key and cp:
            hub_key = cp.get("api_key", "")
        self.url_edit = QLineEdit(hub_url or ""); self.url_edit.setMinimumHeight(BUTTON_HEIGHT)
        self.key_edit = QLineEdit(hub_key or ""); self.key_edit.setMinimumHeight(BUTTON_HEIGHT); self.key_edit.setEchoMode(QLineEdit.Password)
        form.addRow(label("Hub URL", "Muted"), self.url_edit)
        form.addRow(label("Hub API key", "Muted"), self.key_edit)
        self.dir_edit = QLineEdit(os.path.join(
            (os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack", "staging"))
        self.dir_edit.setMinimumHeight(BUTTON_HEIGHT)
        row = QHBoxLayout(); row.addWidget(self.dir_edit, 1)
        row.addWidget(_button("Browse…", self._browse_dir))
        form.addRow(label("Backup base dir", "Muted"), row)
        outer.addWidget(frame)

        self.status = label("", "Muted"); outer.addWidget(self.status)
        outer.addStretch(1)
        buttons = QHBoxLayout(); buttons.addStretch(1)
        self._cancel_btn = _button("Cancel", self.reject)
        self._go_btn = _button("Discover", self._discover, "Primary")
        self._go_btn.setDefault(True)
        buttons.addWidget(self._cancel_btn); buttons.addWidget(self._go_btn)
        outer.addLayout(buttons)

    def _browse_dir(self):
        path = _pick_folder(self, "Choose backup base directory", self.dir_edit.text())
        if path:
            self.dir_edit.setText(path)

    def _say(self, message, kind=None):
        set_status(self.status, message, kind or _kind(message))

    def _discover(self):
        url = self.url_edit.text().strip()
        key = self.key_edit.text().strip()
        if not url or not key:
            _error(self, "Required", "Hub URL and API key are required.")
            return
        self._go_btn.setEnabled(False)
        self._say("Connecting to hub…", "warn")
        base_dir = self.dir_edit.text().strip()
        global_cloud = _app_global_cloud(self._app)     # read on the main thread
        relay = self._relay

        def progress(text):
            relay.call.emit(lambda: self._alive and self._say(text, "warn"))

        def work():
            from hub_discovery import HubDiscovery, build_profiles_from_spokes
            disc = HubDiscovery(url, api_key=key)
            try:
                progress("Connected. Fetching spoke list…")
                hub_info, spokes = disc.discover_spokes()
                spoke_configs = {}
                for i, spoke in enumerate(spokes):
                    spoke_url = spoke.get("site_url", "").rstrip("/")
                    api_key = spoke.get("api_key_remote", "")
                    progress(f"Querying spoke {i + 1}/{len(spokes)}: {spoke.get('site_name', '?')}…")
                    if spoke_url and api_key:
                        cfg = disc.fetch_spoke_backup_config(spoke_url, api_key)
                        if cfg:
                            spoke_configs[spoke_url] = cfg
            finally:
                disc.close()
            return build_profiles_from_spokes(hub_info, spokes, spoke_configs, base_dir,
                                              global_cloud=global_cloud, hub_api_key=key)

        self._run(work, self._on_done, self._on_error)

    def _on_done(self, profiles):
        self._go_btn.setEnabled(True)
        if not profiles:
            self._say("No blogs found.", "warn")
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
        self.created_count = created
        msg = f"Done! Created {created} profile(s)."
        if skipped:
            msg += f" Skipped {skipped} existing."
        self._say(msg, "good")
        self._cancel_btn.setText("Close")
        if created > 0:
            _info(self, "Discovery complete",
                  f"{msg}\n\nYou'll still need to enter FTP credentials and backup\n"
                  "directories for each spoke if they weren't auto-populated.")

    def _on_error(self, exc):
        self._say(f"Error: {exc}", "bad")
        self._go_btn.setEnabled(True)


# ── Delete ─────────────────────────────────────────────────────────────────

def delete_profile_confirmed(parent, name):
    """Ask (Tk's exact warning), then delete the profile. True only if deleted."""
    if not name:
        _info(parent, "No profile", "Select a profile first.")
        return False
    box = QMessageBox(parent)
    box.setWindowTitle("Delete profile")
    box.setIcon(QMessageBox.Warning)
    box.setText(
        f'Delete the profile "{name}"?\n\n'
        "This removes only its SUYB connection settings on this computer "
        "(site URL, FTP and admin credentials, schedule). Backups already "
        "saved to disk or the cloud are NOT touched.")
    delete = box.addButton("Delete these settings", QMessageBox.DestructiveRole)
    delete.setObjectName("Danger"); delete.setMinimumHeight(BUTTON_HEIGHT)
    keep = box.addButton("Keep it", QMessageBox.RejectRole)
    keep.setMinimumHeight(BUTTON_HEIGHT)
    box.setDefaultButton(keep); box.setEscapeButton(keep)
    box.exec()
    if box.clickedButton() is not delete:
        return False
    profile_manager.delete_profile(name)
    return True

# ===== SNAPSMACK EOF =====
