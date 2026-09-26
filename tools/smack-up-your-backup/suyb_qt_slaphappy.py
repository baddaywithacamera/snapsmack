"""SMACK UP YOUR BACKUP — SLAP HAPPY page (Qt).

Qt rebuild of the Tk SlapHappyTab (main.py): backs up SNAP SLAPPER's settings,
catalog, photographs and editable projects (plus SUYB's own settings, minus
passwords) into a dated ZIP, either kept in a local folder or uploaded to a
site's cloud destination. The engine is slap_happy.py, unchanged; this page
calls it exactly as the Tk tab did, on a worker thread.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import threading

from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QFileDialog, QGridLayout, QHBoxLayout, QLineEdit,
    QMessageBox, QProgressBar, QPushButton, QWidget,
)

import cloud_client as cloud_module
import profile_manager
import slap_happy
from suyb_qt_common import Relay, card, label, make_page, set_status


LOCAL = "Local folder"
PARTS = (("suyb_settings", "SUYB settings"),
         ("settings", "SLAPPER settings"),
         ("catalog", "Catalog + cache"),
         ("photos", "Photographs"),
         ("projects", "Editable projects"))
MODES = (("Only what changed since last time (incremental)", "incremental"),
         ("Everything (full)", "full"))
TARGET_HEIGHT = 40


def snapsmack_home():
    """Same rule as the Tk tab: SNAPSMACK_HOME, else C:\\snapsmack."""
    return os.path.abspath((os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack")


def run_slap_happy(home, folder, mode, components, label_text, profile, global_cloud, progress):
    """The Tk work() body, verbatim in behaviour. Runs on a worker thread.

    Local: package + advance the incremental baseline at once.
    Cloud: package without advancing, upload, verify, THEN advance.
    """
    result = slap_happy.create_backup(
        home, folder, mode=mode, components=components,
        destination_key=label_text, commit=profile is None, on_progress=progress)
    if profile is not None:
        progress("Uploading", 0, 1)
        client = cloud_module.get_cloud_client(profile, global_cloud)
        if client is None:
            raise RuntimeError("That cloud destination is not configured.")
        remote_id = client.upload_file(result["path"], os.path.basename(result["path"]))
        if not remote_id or not client.verify_upload(remote_id, result["path"]):
            raise RuntimeError("Cloud upload could not be verified; incremental state was not advanced.")
        slap_happy.commit_backup_state(result)
        result["cloud"] = label_text
    return result


class SlapHappyPage(QWidget):
    def __init__(self, window):
        super().__init__()
        self._window = window
        self._relay = Relay(self)
        self._running = False
        self._thread = None
        self._destinations = {LOCAL: None}
        layout = make_page(self, "SLAP HAPPY",
                           "Back up SNAP SLAPPER's brain, photographs, and editable work.")

        handoff, hl = card("SNAP SLAPPER handoff",
                           "Where SNAP SLAPPER says its settings, catalog and photos live.")
        self._paths = label("Open this page to look for SNAP SLAPPER.", "Muted")
        hl.addWidget(self._paths)
        layout.addWidget(handoff)

        what, wl = card("What gets saved")
        parts_row = QHBoxLayout(); parts_row.setSpacing(18)
        self._parts = {}
        for key, text in PARTS:
            box = QCheckBox(text); box.setChecked(True); box.setMinimumHeight(TARGET_HEIGHT)
            self._parts[key] = box
            parts_row.addWidget(box)
        parts_row.addStretch(1)
        wl.addLayout(parts_row)
        grid = QGridLayout(); grid.setHorizontalSpacing(14); grid.setVerticalSpacing(8)
        grid.addWidget(label("Backup type", "Muted"), 0, 0)
        self._mode = QComboBox(); self._mode.setMinimumHeight(TARGET_HEIGHT)
        for text, value in MODES:
            self._mode.addItem(text, value)
        grid.addWidget(self._mode, 0, 1)
        grid.addWidget(label("Destination", "Muted"), 1, 0)
        self._destination = QComboBox(); self._destination.setMinimumHeight(TARGET_HEIGHT)
        self._destination.addItem(LOCAL)
        grid.addWidget(self._destination, 1, 1)
        grid.setColumnStretch(2, 1)
        wl.addLayout(grid)
        layout.addWidget(what)

        local, ll = card("Local staging folder",
                         "The ZIP is always made here first. For a cloud destination it is "
                         "then uploaded and checked.")
        folder_row = QHBoxLayout()
        self._folder = QLineEdit(os.path.join(snapsmack_home(), "backups", "slap-happy"))
        self._folder.setMinimumHeight(TARGET_HEIGHT)
        folder_row.addWidget(self._folder, 1)
        self._browse_btn = QPushButton("Browse…"); self._browse_btn.setMinimumHeight(TARGET_HEIGHT)
        self._browse_btn.clicked.connect(self._browse)
        folder_row.addWidget(self._browse_btn)
        ll.addLayout(folder_row)
        layout.addWidget(local)

        action = QHBoxLayout()
        self._run_btn = QPushButton("PUNCH IT"); self._run_btn.setObjectName("Primary")
        self._run_btn.setMinimumHeight(TARGET_HEIGHT + 4)
        self._run_btn.clicked.connect(self._run)
        action.addWidget(self._run_btn)
        self._status = label("Ready.", "Muted")
        action.addWidget(self._status, 1)
        layout.addLayout(action)
        self._progress = QProgressBar(); self._progress.setRange(0, 1); self._progress.setValue(0)
        layout.addWidget(self._progress)
        layout.addStretch(1)

    # ── Page contract ────────────────────────────────────────────────────
    def refresh(self):
        home = snapsmack_home()
        contract = slap_happy.discover_contract(home)
        roots = contract["image_roots"]
        saved = contract.get("saved_roots", [])
        self._paths.setText(
            f"Settings: {contract['config_dir']}\nCatalog: {contract['catalog_dir']}\n"
            f"Original locations: {len(roots)} chosen by photographer"
            + ("\n" + "\n".join(f"  • {path}" for path in roots) if roots else "")
            + f"\nSaved image/project locations: {len(saved)} chosen by photographer"
            + ("\n" + "\n".join(f"  • {path}" for path in saved) if saved else ""))
        global_cloud = self._global_cloud()
        destinations = {LOCAL: None}
        for name in profile_manager.list_profiles():
            profile = profile_manager.load_profile(name)
            if not profile:
                continue
            try:
                client = cloud_module.get_cloud_client(profile, global_cloud)
            except Exception:
                client = None
            if client:
                provider = profile.get("cloud_provider") or global_cloud.get("cloud_provider", "cloud")
                destinations[f"Cloud — {name} ({provider.replace('_', ' ').title()})"] = profile
        self._destinations = destinations
        current = self._destination.currentText()
        self._destination.clear()
        self._destination.addItems(list(destinations))
        index = self._destination.findText(current)
        self._destination.setCurrentIndex(index if index >= 0 else 0)

    def help_topics(self):
        return help_topics()

    def shutdown(self):
        """The engine has no cancel; the worker is a daemon thread and dies with the app."""
        return None

    # ── Helpers ──────────────────────────────────────────────────────────
    def _global_cloud(self):
        try:
            return self._window.global_cloud() or {}
        except Exception:
            return {}

    def _tell(self, kind, title, text):
        """One place for pop-ups (tests replace this)."""
        if kind == "error":
            QMessageBox.critical(self, title, text)
        else:
            QMessageBox.information(self, title, text)

    def _browse(self):
        folder = QFileDialog.getExistingDirectory(
            self, "Choose SLAP HAPPY staging folder", self._folder.text().strip())
        if folder:
            self._folder.setText(os.path.normpath(folder))

    # ── Run ──────────────────────────────────────────────────────────────
    def _run(self):
        if self._running:
            return
        components = [key for key, box in self._parts.items() if box.isChecked()]
        if not components:
            self._tell("info", "Pick something", "Choose at least one thing to back up.")
            return
        folder = self._folder.text().strip()
        if not folder:
            self._tell("info", "Pick a folder", "Choose a local staging folder.")
            return
        label_text = self._destination.currentText() or LOCAL
        profile = self._destinations.get(label_text)
        mode = self._mode.currentData()
        global_cloud = self._global_cloud()
        home = snapsmack_home()
        self._running = True
        self._run_btn.setEnabled(False)
        self._status.setText("Finding SNAP SLAPPER data…")
        self._progress.setRange(0, 1); self._progress.setValue(0)

        def progress(stage, done, total):
            self._relay.call.emit(lambda s=stage, d=done, t=total: self.on_progress(s, d, t))

        def work():
            try:
                result = run_slap_happy(home, folder, mode, components, label_text,
                                        profile, global_cloud, progress)
            except Exception as exc:
                self._relay.call.emit(lambda m=str(exc): self.on_error(m))
                return
            self._relay.call.emit(lambda r=result: self.on_done(r))

        self._thread = threading.Thread(target=work, daemon=True, name="SlapHappy")
        self._thread.start()

    def on_progress(self, stage, done, total):
        self._status.setText(f"{stage}… {done:,}/{total:,}" if total else f"{stage}…")
        self._progress.setRange(0, max(int(total), 1))
        self._progress.setValue(int(done))

    def on_done(self, result):
        self._running = False
        self._run_btn.setEnabled(True)
        destination = result.get("cloud", "local storage")
        set_status(self._status, f"Done — {result['changed']:,} file(s) packed to {destination}.", "good")
        self._tell("info", "SLAP HAPPY", f"Backup verified.\n\n{result['path']}")

    def on_error(self, message):
        self._running = False
        self._run_btn.setEnabled(True)
        set_status(self._status, "Backup stopped safely.", "bad")
        self._tell("error", "SLAP HAPPY could not finish", message)


def help_topics():
    return [
        ("SLAP HAPPY page", (
            "SLAP HAPPY backs up SNAP SLAPPER on this computer into one dated ZIP file.\n\n"
            "SNAP SLAPPER handoff shows where SNAP SLAPPER keeps its settings and catalog, "
            "and which photo folders you chose in SNAP SLAPPER.\n\n"
            "What gets saved — tick any of: SUYB settings (your site list and preferences, "
            "with every password and key removed), SLAPPER settings, Catalog + cache, "
            "Photographs, Editable projects (.slapper and .slaprecipe files).\n\n"
            "Backup type — \"Only what changed\" packs just the files that are new or "
            "changed since the last SLAP HAPPY backup to the same destination, and lists "
            "files deleted since then. \"Everything\" packs every file.\n\n"
            "Destination — Local folder keeps the ZIP in the staging folder. A Cloud "
            "choice uploads the ZIP to that site's Google Drive or Box and checks the "
            "upload; \"only what changed\" is remembered only after that check passes, so "
            "a failed upload never loses changes.\n\n"
            "Local staging folder — where the ZIP is made. Browse… picks a folder.\n\n"
            "PUNCH IT starts the backup. The bar shows scanning, packing and uploading. "
            "When it finishes you see where the ZIP went; if it stops, you see why and "
            "nothing is marked as backed up.")),
    ]

# ===== SNAPSMACK EOF =====
