"""DRIVE BY main window — Read-only inventory by default + safe prune mode (SS-1364)."""

import os
import sys

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QDialog, QFormLayout, QFrame, QHBoxLayout,
    QHeaderView, QLabel, QLineEdit, QListWidget, QMainWindow, QPushButton,
    QSpinBox, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
)

from _version import BUILD_VERSION
from driveby_core.audit_log import append_deletion_record
from driveby_core.auth_state import (
    CRED_B2_APP_KEY, CRED_B2_KEY_ID, DEFAULT_GRANT_LIFETIME_DAYS,
    DRIVE_SCOPE, GrantStatus, get_grant_status, record_drive_grant,
    revoke_drive_grant, validate_b2_credentials,
)
from driveby_core.models import BackupRecord, format_bytes, summarize_inventory
from driveby_core.providers import (
    FakeB2Provider, FakeDriveProvider, PROVIDER_LABELS, RECOVERY_WINDOWS_DAYS,
)
from driveby_core.retention import KeepRule, PrunePlan, build_prune_plan


class _MemoryCredStore:
    """Fallback in-memory store when snap_creds is unavailable in isolated tests."""

    def __init__(self):
        self._data: dict[str, str] = {}

    def get(self, key: str, default: str = "") -> str:
        return self._data.get(key, default)

    def set(self, key: str, value: str):
        self._data[key] = str(value)


def _default_cred_store():
    shared_dir = os.path.normpath(os.path.join(os.path.dirname(__file__), "..", "..", "_shared"))
    if os.path.isdir(shared_dir) and shared_dir not in sys.path:
        sys.path.insert(0, shared_dir)
    try:
        import snap_creds  # type: ignore
        snap_creds.init()
        return snap_creds
    except Exception:  # noqa: BLE001
        return _MemoryCredStore()


class CredentialsDialog(QDialog):
    """Manage DRIVE BY's own credentials (drive.file + bucket-restricted B2 app key)."""

    def __init__(self, store, post_fn=None, parent=None):
        super().__init__(parent)
        self.setWindowTitle("DRIVE BY — Link & Keys (isolated from SUYB)")
        self.resize(520, 320)
        self._store = store
        self._post_fn = post_fn

        layout = QVBoxLayout(self)
        form = QFormLayout()

        scope_lbl = QLabel(f"Google Drive scope: {DRIVE_SCOPE}")
        scope_lbl.setObjectName("Muted")
        layout.addWidget(scope_lbl)

        self.drive_token_edit = QLineEdit()
        self.drive_token_edit.setEchoMode(QLineEdit.Password)
        self.drive_token_edit.setPlaceholderText("OAuth refresh token (drive.file only)")
        form.addRow("Drive refresh token:", self.drive_token_edit)

        self.lifetime_spin = QSpinBox()
        self.lifetime_spin.setRange(1, 90)
        self.lifetime_spin.setValue(DEFAULT_GRANT_LIFETIME_DAYS)
        form.addRow("Grant lifetime (days):", self.lifetime_spin)

        drive_btns = QHBoxLayout()
        self.save_drive_btn = QPushButton("Save Drive Grant")
        self.save_drive_btn.setObjectName("Primary")
        self.save_drive_btn.clicked.connect(self.save_drive_grant)
        drive_btns.addWidget(self.save_drive_btn)

        self.revoke_drive_btn = QPushButton("Revoke Drive Grant")
        self.revoke_drive_btn.clicked.connect(self.revoke_drive)
        drive_btns.addWidget(self.revoke_drive_btn)
        form.addRow("", drive_btns)

        self.b2_key_id_edit = QLineEdit(str(self._store.get(CRED_B2_KEY_ID, "") or ""))
        self.b2_key_id_edit.setPlaceholderText("Bucket-restricted key ID (starts with K)")
        form.addRow("B2 App Key ID:", self.b2_key_id_edit)

        self.b2_app_key_edit = QLineEdit(str(self._store.get(CRED_B2_APP_KEY, "") or ""))
        self.b2_app_key_edit.setEchoMode(QLineEdit.Password)
        self.b2_app_key_edit.setPlaceholderText("Bucket-restricted B2 application key")
        form.addRow("B2 App Key:", self.b2_app_key_edit)

        self.save_b2_btn = QPushButton("Save B2 App Key")
        self.save_b2_btn.clicked.connect(self.save_b2_keys)
        form.addRow("", self.save_b2_btn)

        layout.addLayout(form)

        self.status_lbl = QLabel("")
        self.status_lbl.setWordWrap(True)
        layout.addWidget(self.status_lbl)

        close_btn = QPushButton("Close")
        close_btn.clicked.connect(self.accept)
        layout.addWidget(close_btn, alignment=Qt.AlignRight)

    def save_drive_grant(self) -> bool:
        tok = self.drive_token_edit.text().strip()
        if not tok:
            self.status_lbl.setText("Drive refresh token cannot be empty.")
            return False
        record_drive_grant(self._store, tok, lifetime_days=self.lifetime_spin.value())
        self.drive_token_edit.clear()
        self.status_lbl.setText(f"Drive grant saved ({self.lifetime_spin.value()}d lifetime).")
        return True

    def revoke_drive(self) -> tuple[bool, str]:
        ok, msg = revoke_drive_grant(self._store, post_fn=self._post_fn)
        self.status_lbl.setText(msg)
        return ok, msg

    def save_b2_keys(self) -> tuple[bool, str]:
        kid = self.b2_key_id_edit.text().strip()
        secret = self.b2_app_key_edit.text().strip()
        ok, msg = validate_b2_credentials(kid, secret)
        if not ok:
            self.status_lbl.setText(msg)
            return False, msg
        self._store.set(CRED_B2_KEY_ID, kid)
        self._store.set(CRED_B2_APP_KEY, secret)
        self.status_lbl.setText(msg)
        return True, msg


class MainWindow(QMainWindow):
    """Backup inventory and safe pruning shell. Starts strictly read-only."""

    def __init__(self, providers=None, log_path: str = None, cred_store=None, post_fn=None):
        super().__init__()
        self.setWindowTitle(f"DRIVE BY — build {BUILD_VERSION}")
        self.resize(1240, 820)
        self.setMinimumSize(960, 660)

        self._read_only = True
        self._providers = providers or [FakeDriveProvider(), FakeB2Provider()]
        self._provider_map = {p.provider_id: p for p in self._providers}
        self._log_path = log_path
        self._cred_store = cred_store if cred_store is not None else _default_cred_store()
        self._post_fn = post_fn
        self._all_records: list[BackupRecord] = []
        self._plan: PrunePlan = PrunePlan([], [], set(), 0, {})
        self._last_grant_msg: str = ""

        central = QWidget()
        root = QVBoxLayout(central)
        root.setContentsMargins(0, 0, 0, 0)
        root.setSpacing(0)

        # Header bar
        header = QFrame()
        header.setObjectName("HeaderBar")
        h_row = QHBoxLayout(header)
        h_row.setContentsMargins(16, 10, 16, 10)
        h_row.setSpacing(10)

        title = QLabel("DRIVE BY")
        title.setObjectName("AppTitle")
        h_row.addWidget(title)

        self.mode_badge = QLabel("READ-ONLY INVENTORY")
        self.mode_badge.setObjectName("ReadOnlyBadge")
        h_row.addWidget(self.mode_badge)

        self.grant_lbl = QLabel("Fixtures (offline) · Drive grant: 7d default")
        self.grant_lbl.setObjectName("Muted")
        h_row.addWidget(self.grant_lbl)
        h_row.addStretch(1)

        h_row.addWidget(QLabel("Provider:"))
        self.provider_filter = QComboBox()
        self.provider_filter.addItem("All providers", "all")
        self.provider_filter.addItem("Google Drive", "google_drive")
        self.provider_filter.addItem("Backblaze B2", "b2")
        self.provider_filter.currentIndexChanged.connect(self._apply_filters)
        h_row.addWidget(self.provider_filter)

        h_row.addWidget(QLabel("Blog:"))
        self.blog_filter = QComboBox()
        self.blog_filter.addItem("All blogs", "all")
        self.blog_filter.currentIndexChanged.connect(self._apply_filters)
        h_row.addWidget(self.blog_filter)

        self.keys_btn = QPushButton("Link / Keys…")
        self.keys_btn.clicked.connect(self.open_credentials_dialog)
        h_row.addWidget(self.keys_btn)

        self.revoke_btn = QPushButton("Revoke Drive Grant")
        self.revoke_btn.clicked.connect(self.revoke_drive_grant_action)
        h_row.addWidget(self.revoke_btn)

        self.mode_toggle_btn = QPushButton("Switch to Prune mode")
        self.mode_toggle_btn.setCheckable(True)
        self.mode_toggle_btn.toggled.connect(self._set_prune_mode)
        h_row.addWidget(self.mode_toggle_btn)

        self.refresh_btn = QPushButton("Refresh inventory")
        self.refresh_btn.setObjectName("Primary")
        self.refresh_btn.clicked.connect(self.refresh_inventory)
        h_row.addWidget(self.refresh_btn)
        root.addWidget(header)

        # Totals summary bar
        summary = QFrame()
        summary.setObjectName("SummaryBar")
        s_row = QHBoxLayout(summary)
        s_row.setContentsMargins(16, 8, 16, 8)
        s_row.setSpacing(18)
        self.total_lbl = QLabel("Total: 0 B")
        self.total_lbl.setObjectName("SummaryStat")
        self.drive_lbl = QLabel("Google Drive: 0 B")
        self.b2_lbl = QLabel("Backblaze B2: 0 B")
        self.blogs_lbl = QLabel("Blogs: —")
        self.blogs_lbl.setObjectName("Muted")
        s_row.addWidget(self.total_lbl)
        s_row.addWidget(self.drive_lbl)
        s_row.addWidget(self.b2_lbl)
        s_row.addWidget(self.blogs_lbl, 1)
        root.addWidget(summary)

        # Inventory table
        self.table = QTableWidget(0, 8)
        self.table.setHorizontalHeaderLabels([
            "Blog", "Provider", "Date", "Type", "Size",
            "Verified", "Replayed (SS-1363)", "Filename",
        ])
        self.table.setEditTriggers(QTableWidget.NoEditTriggers)
        self.table.setSelectionBehavior(QTableWidget.SelectRows)
        self.table.setSortingEnabled(True)
        self.table.verticalHeader().setVisible(False)
        hdr = self.table.horizontalHeader()
        hdr.setSectionResizeMode(QHeaderView.ResizeToContents)
        hdr.setSectionResizeMode(7, QHeaderView.Stretch)
        root.addWidget(self.table, 1)

        # Prune drawer (hidden in read-only inventory mode)
        self.prune_panel = QFrame()
        self.prune_panel.setObjectName("SummaryBar")
        p_col = QVBoxLayout(self.prune_panel)
        p_col.setContentsMargins(16, 10, 16, 12)
        p_col.setSpacing(8)

        ctrl = QHBoxLayout()
        ctrl.addWidget(QLabel("Keep last:"))
        self.keep_n_spin = QSpinBox()
        self.keep_n_spin.setRange(1, 30)
        self.keep_n_spin.setValue(2)
        ctrl.addWidget(self.keep_n_spin)
        self.keep_monthly_cb = QCheckBox("Plus 1 per month")
        self.keep_monthly_cb.setChecked(True)
        ctrl.addWidget(self.keep_monthly_cb)
        self.preview_btn = QPushButton("Preview prune")
        self.preview_btn.setObjectName("Primary")
        self.preview_btn.clicked.connect(self.preview_prune)
        ctrl.addWidget(self.preview_btn)
        self.preview_lbl = QLabel("Newest backup and newest verified backup per blog are always protected.")
        self.preview_lbl.setObjectName("Muted")
        ctrl.addWidget(self.preview_lbl, 1)
        p_col.addLayout(ctrl)

        self.preview_list = QListWidget()
        self.preview_list.setMaximumHeight(120)
        p_col.addWidget(self.preview_list)

        confirm_row = QHBoxLayout()
        self.confirm_prompt_lbl = QLabel("Type DELETE 0 BACKUPS to confirm:")
        confirm_row.addWidget(self.confirm_prompt_lbl)
        self.confirm_input = QLineEdit()
        self.confirm_input.setPlaceholderText("Typed confirmation required")
        self.confirm_input.textChanged.connect(self._sync_execute_btn)
        confirm_row.addWidget(self.confirm_input, 1)
        self.execute_btn = QPushButton("Move to Trash / Hide")
        self.execute_btn.setEnabled(False)
        self.execute_btn.clicked.connect(self.execute_prune)
        confirm_row.addWidget(self.execute_btn)
        p_col.addLayout(confirm_row)

        self.prune_panel.setVisible(False)
        root.addWidget(self.prune_panel)

        self.setCentralWidget(central)
        self._refresh_grant_status()
        self.refresh_inventory()

    def is_read_only(self) -> bool:
        return self._read_only

    def _set_prune_mode(self, enabled: bool):
        self._read_only = not bool(enabled)
        self.prune_panel.setVisible(bool(enabled))
        if self._read_only:
            self.mode_badge.setText("READ-ONLY INVENTORY")
            self.mode_badge.setObjectName("ReadOnlyBadge")
            self.mode_toggle_btn.setText("Switch to Prune mode")
        else:
            self.mode_badge.setText("PRUNE MODE (TRASH / HIDE)")
            self.mode_badge.setObjectName("DeleteBadge")
            self.mode_toggle_btn.setText("Return to Read-Only")
            self.preview_prune()
        self.mode_badge.style().unpolish(self.mode_badge)
        self.mode_badge.style().polish(self.mode_badge)

    def refresh_inventory(self):
        records = []
        for provider in self._providers:
            records.extend(provider.list_backups())
        records.sort(key=lambda r: (r.date_iso, r.blog, r.provider), reverse=True)
        self._all_records = records

        current_blog = self.blog_filter.currentData()
        self.blog_filter.blockSignals(True)
        self.blog_filter.clear()
        self.blog_filter.addItem("All blogs", "all")
        for blog in sorted({r.blog for r in records}):
            self.blog_filter.addItem(blog, blog)
        idx = self.blog_filter.findData(current_blog)
        self.blog_filter.setCurrentIndex(idx if idx >= 0 else 0)
        self.blog_filter.blockSignals(False)

        self._update_summary(records)
        self._apply_filters()
        if not self._read_only:
            self.preview_prune()

    def _update_summary(self, records):
        s = summarize_inventory(records)
        self.total_lbl.setText(f"Total: {format_bytes(s.total_bytes)} ({s.total_count} backups)")
        self.drive_lbl.setText(
            f"Google Drive: {format_bytes(s.by_provider.get('google_drive', 0))} "
            f"({s.counts_by_provider.get('google_drive', 0)})")
        self.b2_lbl.setText(
            f"Backblaze B2: {format_bytes(s.by_provider.get('b2', 0))} "
            f"({s.counts_by_provider.get('b2', 0)})")
        blog_parts = [f"{b}: {format_bytes(sz)}" for b, sz in sorted(s.by_blog.items())]
        self.blogs_lbl.setText("Per blog: " + "  |  ".join(blog_parts) if blog_parts else "Blogs: —")

    def _apply_filters(self):
        prov = self.provider_filter.currentData() or "all"
        blog = self.blog_filter.currentData() or "all"
        filtered = [
            r for r in self._all_records
            if (prov == "all" or r.provider == prov)
            and (blog == "all" or r.blog == blog)
        ]
        self.table.setSortingEnabled(False)
        self.table.setRowCount(len(filtered))
        for row, rec in enumerate(filtered):
            vals = (
                rec.blog,
                PROVIDER_LABELS.get(rec.provider, rec.provider),
                rec.date_iso or "—",
                rec.backup_type,
                format_bytes(rec.size_bytes),
                "Yes" if rec.verified else "No",
                "Yes" if rec.replayed else "—",
                rec.filename,
            )
            for col, text in enumerate(vals):
                item = QTableWidgetItem(text)
                if col == 4:
                    item.setTextAlignment(Qt.AlignRight | Qt.AlignVCenter)
                self.table.setItem(row, col, item)
        self.table.setSortingEnabled(True)

    def preview_prune(self) -> PrunePlan:
        rule = KeepRule(
            keep_last_n=self.keep_n_spin.value(),
            keep_one_per_month=self.keep_monthly_cb.isChecked(),
        )
        self._plan = build_prune_plan(self._all_records, rule)
        self.preview_list.clear()
        for rec in self._plan.prune:
            prov_lbl = PROVIDER_LABELS.get(rec.provider, rec.provider)
            days = RECOVERY_WINDOWS_DAYS.get(rec.provider, 30)
            action = "Drive Trash" if rec.provider == "google_drive" else "B2 Hide"
            self.preview_list.addItem(
                f"{rec.blog} · {rec.filename} ({format_bytes(rec.size_bytes)}) → {action} ({days}d recovery)")
        n = len(self._plan.prune)
        self.preview_lbl.setText(
            f"Prune {n} backup(s), recover {format_bytes(self._plan.bytes_recovered)} "
            f"(30d recovery in Drive Trash / B2 hidden).")
        self.confirm_prompt_lbl.setText(f"Type DELETE {n} BACKUPS to confirm:")
        self.confirm_input.clear()
        self._sync_execute_btn()
        return self._plan

    def _grant_blocks_deletes(self) -> bool:
        status = get_grant_status(self._cred_store)
        if status.revoke_pending:
            return True
        if status.linked and status.expired:
            return True
        return False

    def _refresh_grant_status(self) -> GrantStatus:
        status = get_grant_status(self._cred_store)
        if status.revoke_pending:
            self.grant_lbl.setText("Drive grant: REVOKE PENDING (deletes locked)")
        elif status.linked and status.expired:
            self.grant_lbl.setText("Drive grant: EXPIRED (re-link required)")
        elif status.linked:
            days_left = status.remaining_seconds / 86400.0
            self.grant_lbl.setText(f"Drive grant: {days_left:.1f}d left (of {status.lifetime_days}d)")
        else:
            self.grant_lbl.setText("Fixtures (offline) · Drive grant: unlinked (7d default)")
        self.revoke_btn.setEnabled(status.linked)
        self._sync_execute_btn()
        return status

    def open_credentials_dialog(self):
        dlg = CredentialsDialog(self._cred_store, post_fn=self._post_fn, parent=self)
        dlg.exec()
        self._refresh_grant_status()

    def revoke_drive_grant_action(self) -> tuple[bool, str]:
        ok, msg = revoke_drive_grant(self._cred_store, post_fn=self._post_fn)
        self._last_grant_msg = msg
        self._refresh_grant_status()
        return ok, msg

    def _expected_confirm_text(self) -> str:
        return f"DELETE {len(self._plan.prune)} BACKUPS"

    def _sync_execute_btn(self):
        n = len(self._plan.prune)
        ok = (
            not self._read_only
            and not self._grant_blocks_deletes()
            and n > 0
            and self.confirm_input.text().strip() == self._expected_confirm_text()
        )
        self.execute_btn.setEnabled(ok)

    def execute_prune(self) -> int:
        if self._read_only or self._grant_blocks_deletes() or not self._plan.prune:
            return 0
        if self.confirm_input.text().strip() != self._expected_confirm_text():
            return 0
        count = 0
        for rec in list(self._plan.prune):
            prov = self._provider_map.get(rec.provider)
            if prov is None:
                continue
            res = prov.trash_backup(rec)
            append_deletion_record(
                rec, res.get("action", "trash"),
                res.get("recovery_days", 30), log_path=self._log_path)
            count += 1
        self.refresh_inventory()
        return count


# ===== SNAPSMACK EOF =====
