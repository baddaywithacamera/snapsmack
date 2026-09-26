"""SMACK UP YOUR BACKUP — Cloud Sync page (Qt).

Port of the Tk `CloudSyncTab` and `_SyncJobDialog` from main.py. Copies files
from one cloud (Google Drive or Backblaze B2) to another, only sending what
changed, and can audit a Backblaze B2 destination and clean up bad copies.

The engines are unchanged and remain the authority:
    sync_manager.py      one JSON file per sync job (list/load/save/delete)
    cloud_sync_engine.py the copy itself (runs on a worker thread)
    b2_integrity.py      Audit & Cleanup inventory, report and cleanup
    cloud_client.py      Google sign-in, token status, B2 connection test

Follows the page contract in suyb_qt_common.py: nothing touches disk or the
network in __init__; refresh() loads the job list; shutdown() stops a run.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import tempfile
import time
from datetime import datetime, timezone
from typing import Optional

from PySide6.QtCore import QTimer
from PySide6.QtWidgets import (
    QButtonGroup, QComboBox, QDialog, QFileDialog, QFrame, QHBoxLayout,
    QLineEdit, QMessageBox, QProgressBar, QPushButton, QRadioButton,
    QTextEdit, QVBoxLayout, QWidget, QGridLayout,
)

import cloud_client as cloud_module
import credential_store as cred_store
import sync_manager
from cloud_sync_engine import CloudSyncEngine
from suyb_qt_common import (
    Relay, card, engine_asker, label, make_page, run_in_thread, set_status,
)


PROVIDER_NAMES = {"google_drive": "Google Drive", "backblaze_b2": "Backblaze B2"}


# --- message boxes behind small functions so tests can answer them ---------

def _info(parent, title, text):
    QMessageBox.information(parent, title, text)


def _error(parent, title, text):
    QMessageBox.critical(parent, title, text)


def _confirm(parent, title, text, yes_text, danger=False):
    """Two plain buttons; the safe one is the default and Esc/close picks it."""
    box = QMessageBox(parent)
    box.setWindowTitle(title); box.setIcon(QMessageBox.Question); box.setText(text)
    yes = box.addButton(yes_text, QMessageBox.AcceptRole)
    if danger:
        yes.setObjectName("Danger")
    no = box.addButton("Cancel", QMessageBox.RejectRole)
    box.setDefaultButton(no); box.setEscapeButton(no)
    box.exec()
    return box.clickedButton() is yes


def _pick_json_file(parent) -> str:
    path, _ = QFileDialog.getOpenFileName(parent, "Select credentials JSON", "", "JSON files (*.json)")
    return path or ""


def fmt_bytes(n) -> str:
    n = int(n or 0)
    if n >= 1_073_741_824:
        return f"{n / 1_073_741_824:.2f} GB"
    if n >= 1_048_576:
        return f"{n / 1_048_576:.1f} MB"
    if n >= 1024:
        return f"{n / 1024:.0f} KB"
    return f"{n} B"


def fmt_time(secs: float) -> str:
    s = int(secs)
    h, rem = divmod(s, 3600)
    m, s = divmod(rem, 60)
    return f"{h:02d}:{m:02d}:{s:02d}" if h else f"{m:02d}:{s:02d}"


def missing_fields(job: dict) -> list:
    """What still needs filling in before this job can run (Tk _start rules)."""
    missing = []
    src_p = job.get("source_provider", "google_drive")
    dst_p = job.get("dest_provider", "backblaze_b2")
    if src_p in ("google_drive", "box"):
        if not job.get("source_credentials_file"):
            missing.append("Source credentials file (OAuth JSON)")
        if not job.get("source_folder"):
            missing.append("Source folder ID")
    elif src_p in ("backblaze_b2", "b2"):
        if not job.get("source_b2_key_id"):
            missing.append("Source B2 Key ID")
        if not job.get("source_b2_app_key"):
            missing.append("Source B2 Application Key")
        if not job.get("source_folder"):
            missing.append("Source bucket name")
    if dst_p in ("google_drive", "box"):
        if not job.get("dest_credentials_file"):
            missing.append("Destination credentials file (OAuth JSON)")
        if not job.get("dest_folder"):
            missing.append("Destination folder")
    elif dst_p in ("backblaze_b2", "b2"):
        if not job.get("dest_b2_key_id"):
            missing.append("Destination B2 Key ID")
        if not job.get("dest_b2_app_key"):
            missing.append("Destination B2 Application Key")
        if not job.get("dest_folder"):
            missing.append("Destination bucket name")
    return missing


def _manifests_dir() -> str:
    """Where Audit & Cleanup writes its CSV reports (same place as Tk)."""
    if getattr(sys, "frozen", False):
        return os.path.join(os.path.dirname(sys.executable), "manifests")
    return os.path.join(os.path.dirname(os.path.abspath(__file__)), "manifests")


def _cleanup_scratch_dir() -> str:
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return tempfile.gettempdir()


# ---------------------------------------------------------------------------
# Sync job editor
# ---------------------------------------------------------------------------

class _Endpoint:
    """One side of a sync job (Source or Dest) with provider-dependent rows."""

    def __init__(self, dialog, side: str, config: dict):
        self.dialog = dialog
        self.side = side                                  # "Source" / "Dest"
        self.prefix = "source" if side == "Source" else "dest"
        self.is_src = side == "Source"
        default = "google_drive" if self.is_src else "backblaze_b2"
        raw = config.get(f"{self.prefix}_provider", default)
        # "b2" is the engine's short alias for Backblaze B2.
        self.original_provider = "backblaze_b2" if raw == "b2" else raw

        self.frame = QFrame(); self.frame.setObjectName("Card")
        grid = QGridLayout(self.frame); grid.setContentsMargins(16, 14, 16, 14)
        grid.setHorizontalSpacing(10); grid.setVerticalSpacing(8)
        self.header = label("", "CardTitle"); grid.addWidget(self.header, 0, 0, 1, 3)

        grid.addWidget(label(f"{side} provider:", "Muted"), 1, 0)
        prov_row = QHBoxLayout()
        self.provider_group = QButtonGroup(self.frame); self.provider_group.setExclusive(True)
        self.provider_buttons = {}
        for display, value in SyncJobDialog.PROVIDERS:
            button = QRadioButton(display)
            self.provider_group.addButton(button); prov_row.addWidget(button)
            self.provider_buttons[value] = button
            if value == self.original_provider:
                button.setChecked(True)
            button.toggled.connect(lambda checked: checked and self.refresh())
        prov_row.addStretch(1)
        grid.addLayout(prov_row, 1, 1, 1, 2)

        # Saved credentials picker (Google Drive only)
        self.picker_lbl = label("Saved credentials:", "Muted"); grid.addWidget(self.picker_lbl, 2, 0)
        self.creds_combo = QComboBox(); grid.addWidget(self.creds_combo, 2, 1)
        self.creds_combo.activated.connect(self._on_cred_picked)
        self.manage_btn = QPushButton("Manage…"); self.manage_btn.clicked.connect(self._manage)
        grid.addWidget(self.manage_btn, 2, 2)

        # Raw credentials file path (Google Drive only)
        self.creds_lbl = label("OAuth client secret JSON:", "Muted"); grid.addWidget(self.creds_lbl, 3, 0)
        self.creds_edit = QLineEdit(config.get(f"{self.prefix}_credentials_file", ""))
        grid.addWidget(self.creds_edit, 3, 1)
        self.browse_btn = QPushButton("Browse…"); self.browse_btn.clicked.connect(self._browse)
        grid.addWidget(self.browse_btn, 3, 2)

        # Backblaze keys (B2 only)
        self.key_lbl = label("Key ID:", "Muted"); grid.addWidget(self.key_lbl, 4, 0)
        self.key_edit = QLineEdit(config.get(f"{self.prefix}_b2_key_id", ""))
        grid.addWidget(self.key_edit, 4, 1, 1, 2)
        self.appkey_lbl = label("Application Key:", "Muted"); grid.addWidget(self.appkey_lbl, 5, 0)
        self.appkey_edit = QLineEdit(config.get(f"{self.prefix}_b2_app_key", ""))
        self.appkey_edit.setEchoMode(QLineEdit.Password)
        grid.addWidget(self.appkey_edit, 5, 1, 1, 2)

        # Folder / bucket
        self.folder_lbl = label("", "Muted"); grid.addWidget(self.folder_lbl, 6, 0)
        self.folder_edit = QLineEdit(config.get(f"{self.prefix}_folder") or config.get(f"{self.prefix}_folder_id", ""))
        grid.addWidget(self.folder_edit, 6, 1, 1, 2)
        self.folder_hint = label("", "Muted"); grid.addWidget(self.folder_hint, 7, 1, 1, 2)

        # Sign in / test connection
        self.auth_btn = QPushButton(""); self.auth_btn.clicked.connect(self._auth_clicked)
        grid.addWidget(self.auth_btn, 8, 0)
        self.auth_status = label("", "Muted"); grid.addWidget(self.auth_status, 8, 1, 1, 2)
        grid.setColumnStretch(1, 1)
        self.refresh()

    # -- state -------------------------------------------------------------

    def provider(self) -> str:
        for value, button in self.provider_buttons.items():
            if button.isChecked():
                return value
        return self.original_provider   # a provider this window has no button for (e.g. box)

    def values(self) -> dict:
        p = self.prefix
        return {
            f"{p}_provider": self.provider(),
            f"{p}_credentials_file": self.creds_edit.text().strip(),
            f"{p}_folder": self.folder_edit.text().strip(),
            f"{p}_b2_key_id": self.key_edit.text().strip(),
            f"{p}_b2_app_key": self.appkey_edit.text().strip(),
        }

    def refresh(self):
        """Show the rows, labels, hint and button that fit the chosen provider."""
        p = self.provider()
        is_b2 = p == "backblaze_b2"
        for widget in (self.picker_lbl, self.creds_combo, self.manage_btn,
                       self.creds_lbl, self.creds_edit, self.browse_btn):
            widget.setVisible(not is_b2)
        for widget in (self.key_lbl, self.key_edit, self.appkey_lbl, self.appkey_edit):
            widget.setVisible(is_b2)
        if not is_b2:
            self._fill_creds_combo()
        if p == "google_drive":
            self.header.setText(f"{self.side}: Google Drive")
            self.folder_lbl.setText("Folder ID:")
            self.folder_hint.setText("Copy from the Drive address: drive.google.com/drive/folders/FOLDER_ID")
            self.auth_btn.setText("Authenticate with Google"); self.auth_btn.setEnabled(True)
            creds = self.creds_edit.text().strip()
            if creds:
                self.auth_status.setText(cloud_module.get_oauth_token_status(creds, readonly=self.is_src))
        elif is_b2:
            self.header.setText(f"{self.side}: Backblaze B2")
            self.folder_lbl.setText("Bucket name:")
            self.folder_hint.setText("Backblaze → Buckets — use the bucket name, not the bucket ID.")
            self.auth_btn.setText("Test connection"); self.auth_btn.setEnabled(True)
            self.auth_status.setText("")
        else:
            self.header.setText(f"{self.side}: {p}")
            self.folder_lbl.setText("Folder:")
            self.folder_hint.setText("")
            self.auth_btn.setText("Not available for this provider"); self.auth_btn.setEnabled(False)

    def _fill_creds_combo(self):
        self.creds_combo.blockSignals(True)
        self.creds_combo.clear()
        self.creds_combo.addItem("")
        self.creds_combo.addItems(cred_store.names())
        path = self.creds_edit.text().strip()
        name = cred_store.name_for(path) if path else None
        index = self.creds_combo.findText(name or "")
        self.creds_combo.setCurrentIndex(max(0, index))
        self.creds_combo.blockSignals(False)

    # -- actions -----------------------------------------------------------

    def _on_cred_picked(self, _index=None):
        path = cred_store.path_for(self.creds_combo.currentText())
        if path:
            self.creds_edit.setText(path)
            self.auth_status.setText(cloud_module.get_oauth_token_status(path, readonly=self.is_src))

    def _manage(self):
        from suyb_qt_credentials import CredLibraryDialog
        dialog = CredLibraryDialog(self.dialog)
        dialog.exec()
        self._fill_creds_combo()
        if dialog.selected_path:
            self.creds_edit.setText(dialog.selected_path)
            self._fill_creds_combo()
            index = self.creds_combo.findText(dialog.selected_name or "")
            if index >= 0:
                self.creds_combo.setCurrentIndex(index)

    def _browse(self):
        path = _pick_json_file(self.dialog)
        if path:
            self.creds_edit.setText(path)

    def _auth_clicked(self):
        if self.provider() == "google_drive":
            self.authenticate()
        elif self.provider() == "backblaze_b2":
            self.test_b2()

    def authenticate(self):
        """Google sign-in in the browser (runs off the main thread)."""
        creds = self.creds_edit.text().strip()
        self.auth_status.setText("Opening browser…"); self.auth_btn.setEnabled(False)
        readonly = self.is_src

        def done(result):
            self.auth_btn.setEnabled(True)
            ok, msg = result
            self.auth_status.setText(msg)
            if ok:
                from suyb_qt_credentials import offer_save_to_library
                offer_save_to_library(self.dialog, creds)

        def failed(exc):
            self.auth_btn.setEnabled(True)
            self.auth_status.setText(f"✗ {exc}")

        run_in_thread(self.dialog.relay, lambda: cloud_module.authenticate_oauth(creds, readonly=readonly),
                      done, failed)

    def test_b2(self):
        """Check the Backblaze keys and bucket (runs off the main thread)."""
        key, app_key, bucket = (self.key_edit.text().strip(), self.appkey_edit.text().strip(),
                                self.folder_edit.text().strip())
        self.auth_status.setText("Connecting…"); self.auth_btn.setEnabled(False)

        def done(result):
            self.auth_btn.setEnabled(True)
            self.auth_status.setText(result[1])

        def failed(exc):
            self.auth_btn.setEnabled(True)
            self.auth_status.setText(f"✗ {exc}")

        run_in_thread(self.dialog.relay, lambda: cloud_module.test_b2_connection(key, app_key, bucket),
                      done, failed)


class SyncJobDialog(QDialog):
    """Create / edit a cloud sync job. Save writes the job file itself (as Tk did).

    After exec(), `result` is the saved config dict, or None if cancelled.
    """

    # OneDrive retired (Sean's call) — Microsoft's auth model never worked,
    # nothing ever wrote to it, so it's fully removed, not just hidden.
    PROVIDERS = [("Google Drive", "google_drive"), ("Backblaze B2", "backblaze_b2")]

    def __init__(self, parent, config: dict, title: str = "Sync Job"):
        super().__init__(parent)
        self.setWindowTitle(title)
        self.setModal(True)
        self.resize(720, 760)
        self.result = None
        self._config = dict(config)
        self.relay = Relay(self)
        self._build()

    def _build(self):
        layout = QVBoxLayout(self); layout.setContentsMargins(18, 16, 18, 16); layout.setSpacing(10)
        name_row = QHBoxLayout()
        name_row.addWidget(label("Job name:", "Muted"))
        self.name_edit = QLineEdit(self._config.get("name", ""))
        name_row.addWidget(self.name_edit, 1)
        layout.addLayout(name_row)
        self.source = _Endpoint(self, "Source", self._config)
        self.dest = _Endpoint(self, "Dest", self._config)
        layout.addWidget(self.source.frame); layout.addWidget(self.dest.frame)
        layout.addStretch(1)
        buttons = QHBoxLayout()
        self.save_btn = QPushButton("Save"); self.save_btn.setObjectName("Primary")
        self.save_btn.clicked.connect(self._save); buttons.addWidget(self.save_btn)
        cancel = QPushButton("Cancel"); cancel.clicked.connect(self.reject); buttons.addWidget(cancel)
        buttons.addStretch(1)
        layout.addLayout(buttons)

    def build_result(self) -> dict:
        """The config dict Save writes — same keys and shape as Tk _SyncJobDialog._save."""
        result = dict(self._config)
        result["name"] = self.name_edit.text().strip()
        result.update(self.source.values())
        result.update(self.dest.values())
        return result

    def _save(self):
        name = self.name_edit.text().strip()
        if not name:
            _error(self, "Name required", "Enter a job name.")
            return
        result = self.build_result()
        old_name = self._config.get("name", "")
        try:
            if old_name and old_name != name:
                sync_manager.delete_job(old_name)
            sync_manager.save_job(result)
        except Exception as exc:
            _error(self, "Save failed", f"Could not write job file:\n{exc}")
            return
        self.result = result
        self.accept()


# ---------------------------------------------------------------------------
# Page
# ---------------------------------------------------------------------------

class CloudSyncPage(QWidget):
    """Cloud-to-cloud sync: Google Drive → Backblaze B2 differential file copy."""

    def __init__(self, window):
        super().__init__()
        self.window = window
        self.relay = Relay(self)
        self._busy = False
        self._engine: Optional[CloudSyncEngine] = None
        self._running_job = ""
        self._start_time = None
        self._last_pct = 0.0
        self._cleanup_cancelled = False
        self._clock = QTimer(self); self._clock.setInterval(1000); self._clock.timeout.connect(self._tick)
        self._build()

    # -- build -------------------------------------------------------------

    def _build(self):
        layout = make_page(self, "Cloud Sync",
                           "Copy files from one cloud to another, sending only what changed. "
                           "Every copy is checked on arrival.")
        jobs, jl = card("Sync job")
        row = QHBoxLayout()
        self.job_combo = QComboBox(); self.job_combo.setMinimumWidth(320)
        self.job_combo.currentTextChanged.connect(lambda _t: self._update_status_labels())
        row.addWidget(self.job_combo, 1)
        self.new_btn = QPushButton("New job…"); self.new_btn.clicked.connect(self._new_job); row.addWidget(self.new_btn)
        self.edit_btn = QPushButton("Edit job…"); self.edit_btn.clicked.connect(self._edit_job); row.addWidget(self.edit_btn)
        self.delete_btn = QPushButton("Delete job"); self.delete_btn.setObjectName("Danger")
        self.delete_btn.clicked.connect(self._delete_job); row.addWidget(self.delete_btn)
        jl.addLayout(row)
        self.src_lbl = label("Source: —", "Muted"); jl.addWidget(self.src_lbl)
        self.dst_lbl = label("Destination: —", "Muted"); jl.addWidget(self.dst_lbl)
        layout.addWidget(jobs)

        run, rl = card("Run")
        buttons = QHBoxLayout()
        self.run_btn = QPushButton("RUN SYNC"); self.run_btn.setObjectName("Primary")
        self.run_btn.clicked.connect(self._start); buttons.addWidget(self.run_btn)
        self.cancel_btn = QPushButton("Cancel"); self.cancel_btn.setEnabled(False)
        self.cancel_btn.clicked.connect(self._cancel); buttons.addWidget(self.cancel_btn)
        buttons.addSpacing(24)
        self.audit_btn = QPushButton("AUDIT & CLEANUP (Backblaze B2)")
        self.audit_btn.clicked.connect(self._audit_cleanup); buttons.addWidget(self.audit_btn)
        buttons.addStretch(1)
        self.status = label("Ready", "StatusGood"); self.status.setWordWrap(False)
        buttons.addWidget(self.status)
        rl.addLayout(buttons)
        self.progress = QProgressBar(); self.progress.setRange(0, 1000); self.progress.setTextVisible(False)
        rl.addWidget(self.progress)
        stats = QHBoxLayout()
        self.files_lbl = label("", "Muted"); stats.addWidget(self.files_lbl, 2)
        self.bytes_lbl = label("", "Muted"); stats.addWidget(self.bytes_lbl, 1)
        self.time_lbl = label("", "Muted"); stats.addWidget(self.time_lbl, 1)
        rl.addLayout(stats)
        layout.addWidget(run)

        activity, al = card("Activity")
        self.log = QTextEdit(); self.log.setReadOnly(True); self.log.setMinimumHeight(160)
        self.log.setPlaceholderText("Sync activity will appear here.")
        al.addWidget(self.log)
        layout.addWidget(activity, 1)

    # -- contract ------------------------------------------------------------

    def refresh(self):
        self._refresh_jobs()

    def help_topics(self):
        return HELP_TOPICS

    def shutdown(self):
        self._cleanup_cancelled = True
        if self._engine:
            self._engine.cancel()
        self._clock.stop()

    # -- jobs ----------------------------------------------------------------

    def current_job_name(self) -> str:
        return self.job_combo.currentText()

    def _load_job(self, name: str) -> Optional[dict]:
        try:
            return sync_manager.load_job(name)
        except Exception as exc:   # e.g. the credential vault is locked
            _error(self, "Could not open sync job", f"{name}\n\n{exc}")
            return None

    def _refresh_jobs(self, select: str = ""):
        try:
            jobs = sync_manager.list_jobs()
        except Exception as exc:
            jobs = []
            self._append_log(f"✗ Could not read the sync jobs folder: {exc}")
        wanted = select or self.job_combo.currentText()
        self.job_combo.blockSignals(True)
        self.job_combo.clear(); self.job_combo.addItems(jobs)
        if jobs:
            self.job_combo.setCurrentIndex(jobs.index(wanted) if wanted in jobs else 0)
        self.job_combo.blockSignals(False)
        if jobs:
            self._update_status_labels()
        else:
            self.src_lbl.setText("Source: —"); self.dst_lbl.setText("Destination: —")

    def _update_status_labels(self):
        name = self.current_job_name()
        if not name:
            return
        try:
            job = sync_manager.load_job(name)
        except Exception as exc:
            self.src_lbl.setText(f"Source: could not open this job ({exc})"); self.dst_lbl.setText("Destination: —")
            return
        if not job:
            return
        src_p = PROVIDER_NAMES.get(job.get("source_provider", "google_drive"), "—")
        dst_p = PROVIDER_NAMES.get(job.get("dest_provider", "backblaze_b2"), "—")
        src_folder = job.get("source_folder") or job.get("source_folder_id", "") or "—"
        dst_folder = job.get("dest_folder") or job.get("dest_folder_path", "") or "—"
        self.src_lbl.setText(f"Source: {src_p} — {src_folder}")
        self.dst_lbl.setText(f"Destination: {dst_p} — {dst_folder}")

    def _run_job_dialog(self, config: dict, title: str) -> Optional[dict]:
        dialog = SyncJobDialog(self, config, title=title)
        dialog.exec()
        return dialog.result

    def _new_job(self):
        result = self._run_job_dialog(sync_manager.new_job_template(), "New Sync Job")
        if result:
            self._refresh_jobs(select=result["name"])

    def _edit_job(self):
        name = self.current_job_name()
        if not name:
            _info(self, "No job", "Select a sync job first.")
            return
        job = self._load_job(name)
        if not job:
            return
        result = self._run_job_dialog(job, "Edit Sync Job")
        if result:
            self._refresh_jobs(select=result["name"])

    def _delete_job(self):
        name = self.current_job_name()
        if not name:
            return
        if _confirm(self, "Delete job",
                    f"Delete sync job '{name}'?\n\nThis removes the job's settings only. "
                    "No files in either cloud are touched.",
                    "Delete job", danger=True):
            sync_manager.delete_job(name)
            self._refresh_jobs()

    # -- run / cancel --------------------------------------------------------

    def _set_running(self, running: bool):
        self._busy = running
        self.run_btn.setEnabled(not running)
        self.audit_btn.setEnabled(not running)
        self.cancel_btn.setEnabled(running)

    def _start(self):
        if self._busy:
            return
        name = self.current_job_name()
        if not name:
            _info(self, "No job", "Select or create a sync job first.")
            return
        job = self._load_job(name)
        if not job:
            return
        missing = missing_fields(job)
        if missing:
            _error(self, "Missing config", "Please configure:\n• " + "\n• ".join(missing))
            return

        self._running_job = name
        self._set_running(True)
        self.progress.setValue(0)
        self.files_lbl.setText(""); self.bytes_lbl.setText(""); self.time_lbl.setText("")
        self.log.clear()
        self._append_log("Starting sync…")
        set_status(self.status, "Syncing…", "warn")
        self._start_clock()

        emit = self.relay.call.emit
        self._engine = CloudSyncEngine(
            config=job,
            on_log=lambda m: emit(lambda m=m: self._append_log(m)),
            on_progress=lambda p: emit(lambda p=p: self._on_progress(p)),
            on_stats=lambda *a: emit(lambda a=a: self._on_stats(*a)),
            on_done=lambda r: emit(lambda r=r: self._on_done(r)),
            on_ask=engine_asker(self.relay, self, "Sync failure", "Abort sync", lambda: self._engine),
        )
        run_in_thread(self.relay, self._engine.run)

    def _cancel(self):
        self._cleanup_cancelled = True
        if self._engine:
            self._engine.cancel()
            self._append_log("Cancelling — the file in progress will finish first…")
        self.cancel_btn.setEnabled(False)

    # -- engine callbacks (main thread) --------------------------------------

    def _append_log(self, msg):
        self.log.append(str(msg))

    def _on_progress(self, pct: float):
        self._last_pct = float(pct or 0.0)
        self.progress.setValue(int(max(0.0, min(1.0, self._last_pct)) * 1000))

    def _on_stats(self, done, total, skipped, failed, bytes_done, bytes_total):
        self.files_lbl.setText(f"Files: {done} / {total} synced   Skipped: {skipped}   Failed: {failed}")
        self.bytes_lbl.setText(f"{fmt_bytes(bytes_done)} / {fmt_bytes(bytes_total)}")

    def _on_done(self, result: dict):
        self._stop_clock()
        self._engine = None
        self._set_running(False)
        self._on_progress(1.0 if result.get("ok") else self._last_pct)
        if result.get("cancelled"):
            self._append_log("— Sync cancelled.")
            set_status(self.status, "Cancelled", "warn")
        elif result.get("ok"):
            self._append_log(f"✓ Sync complete — {result['files_synced']} file(s), "
                             f"{fmt_bytes(result['bytes_synced'])}.")
            set_status(self.status, "Sync complete", "good")
            name = self._running_job
            if name:
                job = self._load_job(name)
                if job:
                    job["last_sync_date"] = datetime.now(timezone.utc).isoformat()
                    job["last_files_synced"] = result["files_synced"]
                    job["last_bytes_synced"] = result["bytes_synced"]
                    try:
                        sync_manager.save_job(job)
                    except Exception as exc:
                        self._append_log(f"⚠ Could not record the last sync date: {exc}")
            self.window.notify("Cloud Sync complete", f"{name}: {result['files_synced']} file(s) copied.")
        else:
            self._append_log(f"✗ Sync finished with {result.get('files_failed', 0)} failure(s). "
                             + (result.get("error", "") or ""))
            set_status(self.status, "Finished with problems", "bad")
            self.window.notify("Cloud Sync problem", f"{self._running_job}: see the Cloud Sync page.", critical=True)
        self._running_job = ""

    # -- clock ---------------------------------------------------------------

    def _start_clock(self):
        self._start_time = time.monotonic()
        self._last_pct = 0.0
        self._clock.start()
        self._tick()

    def _stop_clock(self):
        self._clock.stop()

    def _tick(self):
        if not self._busy or self._start_time is None:
            return
        elapsed = time.monotonic() - self._start_time
        pct = self._last_pct
        if pct > 0.01:
            eta = elapsed / pct * (1.0 - pct)
            self.time_lbl.setText(f"Elapsed: {fmt_time(elapsed)}   ETA: {fmt_time(eta)}")
        else:
            self.time_lbl.setText(f"Elapsed: {fmt_time(elapsed)}")

    # -- audit & cleanup -----------------------------------------------------

    def _audit_cleanup(self):
        """Inventory source and B2 destination, compare sizes, confirm, then
        delete bad versions and re-send files that have no good copy."""
        if self._busy:
            _info(self, "Busy", "Wait for the current sync to finish.")
            return
        name = self.current_job_name()
        if not name:
            _info(self, "No job", "Select a sync job first.")
            return
        job = self._load_job(name)
        if not job:
            _error(self, "Job not found", f"Could not load job: {name}")
            return
        if job.get("dest_provider") not in ("backblaze_b2", "b2"):
            _info(self, "B2 only", "Audit & Cleanup only works on Backblaze B2 destinations.")
            return
        key_id = job.get("dest_b2_key_id", "").strip()
        app_key = job.get("dest_b2_app_key", "").strip()
        bucket = (job.get("dest_folder") or "").strip()
        if not key_id or not app_key or not bucket:
            _error(self, "Missing config",
                   "Destination B2 Key ID, Application Key, and bucket name are all required.")
            return

        import b2_integrity as bi
        try:
            src_client = CloudSyncEngine._build_client(
                job.get("source_provider", "google_drive"), job, "source", source=True)
            dst_client = cloud_module.B2Client(key_id, app_key, bucket)
        except Exception as exc:
            _error(self, "Connection error", str(exc))
            return

        self._busy = True
        self.run_btn.setEnabled(False); self.audit_btn.setEnabled(False)
        self.log.clear(); self.progress.setValue(0)
        set_status(self.status, "Auditing…", "warn")
        paths = bi.report_paths(_manifests_dir())
        emit = self.relay.call.emit

        def log(m):
            emit(lambda m=m: self._append_log(m))

        def work():
            b2_inv = bi.inventory_b2(dst_client, log)
            src_inv = bi.inventory_source(src_client, log)
            src_rows = [{"filename": k, "size": v["size"], "md5": v.get("md5", "")}
                        for k, v in src_inv.items()]
            dst_rows = [{"filename": k, "version": i + 1, "size": ver["size"],
                         "sha1": ver.get("sha1", ""), "uploaded_ms": ver.get("uploaded_ms", "")}
                        for k, versions in b2_inv.items() for i, ver in enumerate(versions)]
            bi.write_csv(src_rows, paths["src_manifest"])
            bi.write_csv(dst_rows, paths["dst_manifest"])
            report = bi.generate_dedup_report(src_inv, b2_inv, log)
            bi.write_csv(report, paths["dedup_report"])
            return {"report": report, "src_inv": src_inv, "paths": paths,
                    "dst_client": dst_client, "src_client": src_client}

        run_in_thread(self.relay, work, self._on_cleanup_confirm,
                      lambda exc: self._on_cleanup_done({"ok": False, "error": str(exc), "paths": paths}))

    def _on_cleanup_confirm(self, payload: dict):
        import b2_integrity as bi
        report, paths = payload["report"], payload["paths"]
        summary = bi.cleanup_summary(report)
        if summary["to_delete"] == 0 and summary["to_replace"] == 0:
            self._end_cleanup()
            self._append_log(f"✓ Audit complete — no issues found.\n"
                             f"  {summary['ok']} file(s) verified, "
                             f"{summary['orphans']} orphan(s) on B2 not in source.")
            self._append_log(f"  Report: {paths['dedup_report']}")
            set_status(self.status, "Audit: no issues", "good")
            return
        msg = (f"Audit complete. Here's what needs to happen:\n\n"
               f"Versions to delete: {summary['to_delete']}\n"
               f"Files to re-transfer: {summary['to_replace']}\n"
               f"Files missing from destination: {summary['missing']}\n"
               f"Orphans on B2 (not in source): {summary['orphans']}\n"
               f"Already OK: {summary['ok']}\n\n"
               f"Deleting bad versions and re-transferring bad files cannot be undone.\n"
               f"Proceed?")
        if not _confirm(self, "Confirm Cleanup", msg, "Delete and re-transfer", danger=True):
            self._end_cleanup()
            self._append_log("Cleanup cancelled.")
            set_status(self.status, "Cleanup cancelled", "warn")
            return

        self._cleanup_cancelled = False
        self.cancel_btn.setEnabled(True)
        set_status(self.status, "Cleaning up…", "warn")
        scratch = _cleanup_scratch_dir()
        emit = self.relay.call.emit

        def work():
            result = bi.execute_cleanup(
                b2_client=payload["dst_client"], src_client=payload["src_client"],
                src_inventory=payload["src_inv"], report=report, scratch_dir=scratch,
                on_log=lambda m: emit(lambda m=m: self._append_log(m)),
                on_progress=lambda p: emit(lambda p=p: self._on_progress(p)),
                cancelled=lambda: self._cleanup_cancelled,
            )
            bi.write_csv(result["log_rows"], paths["cleanup_log"])
            result["paths"] = paths
            return result

        run_in_thread(self.relay, work, self._on_cleanup_done,
                      lambda exc: self._on_cleanup_done({"ok": False, "error": str(exc), "paths": paths}))

    def _end_cleanup(self):
        self._busy = False
        self.run_btn.setEnabled(True); self.audit_btn.setEnabled(True); self.cancel_btn.setEnabled(False)

    def _on_cleanup_done(self, result: dict):
        self._end_cleanup()
        self.progress.setValue(0)
        if result.get("error"):
            self._append_log(f"✗ Cleanup failed: {result['error']}")
            set_status(self.status, "Cleanup failed", "bad")
            return
        d = result.get("deleted", 0); r = result.get("replaced", 0)
        f = result.get("failed", 0); m = result.get("missing_from_source", 0)
        paths = result.get("paths", {})
        self._append_log(f"{'✓' if f == 0 else '⚠'} Cleanup complete — "
                         f"{d} version(s) deleted, {r} file(s) re-transferred"
                         + (f", {f} failure(s)" if f else "")
                         + (f", {m} missing from source" if m else ""))
        if paths.get("cleanup_log"):
            self._append_log(f"  Log: {paths['cleanup_log']}")
        if paths.get("dedup_report"):
            self._append_log(f"  Report: {paths['dedup_report']}")
        set_status(self.status, "Cleanup complete" if f == 0 else "Cleanup had failures",
                   "good" if f == 0 else "bad")


HELP_TOPICS = [
    ("Cloud Sync",
     "Cloud Sync copies files from one cloud to another — normally from a Google Drive folder into a "
     "Backblaze B2 bucket. It only sends files that are new or changed since the last run.\n\n"
     "Choose a sync job, then RUN SYNC. Progress, file counts, data sent, time elapsed and time left "
     "show under the bar; each file is listed in Activity. Cancel stops after the file in progress.\n\n"
     "Every file is checked on arrival: Backblaze confirms the SHA-1 checksum of what it received, so a "
     "damaged copy is reported as a failure, never counted as done. If several files fail, SUYB stops and "
     "asks whether to abort the sync or continue anyway."),
    ("Sync jobs",
     "New job… and Edit job… open the job window. Give the job a name, then set the Source (where files "
     "come from) and the Dest (where they go).\n\n"
     "Google Drive — pick saved credentials by name (Manage… opens the credential library), or browse to "
     "the OAuth client secret JSON. Folder ID is the last part of the folder's Drive address. Click "
     "Authenticate with Google once; a browser opens for a one-time consent click. A source only ever "
     "asks for read-only access.\n\n"
     "Backblaze B2 — enter the Key ID and Application Key from Backblaze → App Keys, and the bucket name "
     "(not the bucket ID). Test connection checks them.\n\n"
     "Save writes the job straight away. Renaming a job replaces the old one. Delete job removes only the "
     "job's settings; no files in either cloud are touched."),
    ("Audit & Cleanup",
     "AUDIT & CLEANUP works only when the destination is a Backblaze B2 bucket. It lists every file in the "
     "source and every stored version in the bucket, compares sizes, and writes CSV reports into the "
     "manifests folder next to SUYB.\n\n"
     "If nothing is wrong it says so and changes nothing. If it finds bad or duplicate versions, or files "
     "with no good copy, it shows the counts and asks first. Confirming deletes the bad versions and sends "
     "those files again from the source — this cannot be undone. Files in the bucket that are not in the "
     "source (orphans) are reported but left alone."),
    ("Saved credentials",
     "The credential library remembers credentials files under a name you choose, so you pick them by name "
     "instead of hunting for the file each time. Add… registers a file, Rename… changes its name, Remove "
     "takes it off the list (the file itself is not deleted), and Use selected fills it into the job. After "
     "a successful Google sign-in SUYB offers to save the file to the library."),
]

# ===== SNAPSMACK EOF =====
