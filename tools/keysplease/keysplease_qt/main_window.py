"""KEYS PLEASE main window — fleet API key status, create/revoke/delete, and audit (SS-1398 / SS-1400)."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QComboBox, QFrame, QHBoxLayout, QHeaderView, QLabel, QLineEdit,
    QListWidget, QMainWindow, QPushButton, QTableWidget, QTableWidgetItem,
    QVBoxLayout, QWidget,
)

from _version import BUILD_VERSION
from keysplease_core.audit_log import append_action_record
from keysplease_core.models import SiteKeyRecord
from keysplease_core.providers import FakeKeysProvider


class MainWindow(QMainWindow):
    """Read-only fleet key status + session-only step-up create/revoke/delete."""

    def __init__(self, provider=None, log_path: str = None):
        super().__init__()
        self.setWindowTitle(f"KEYS PLEASE — build {BUILD_VERSION}")
        self.resize(1220, 820)
        self.setMinimumSize(940, 640)

        self._provider = provider or FakeKeysProvider()
        self._log_path = log_path
        self._records: list[SiteKeyRecord] = []
        self._batch_action: str = ""  # "revoke" or "delete"
        self._batch_items: list[SiteKeyRecord] = []

        central = QWidget()
        root = QVBoxLayout(central)
        root.setContentsMargins(0, 0, 0, 0)
        root.setSpacing(0)

        # Header
        header = QFrame()
        header.setObjectName("HeaderBar")
        h_row = QHBoxLayout(header)
        h_row.setContentsMargins(16, 10, 16, 10)
        h_row.setSpacing(10)
        title = QLabel("KEYS PLEASE")
        title.setObjectName("AppTitle")
        h_row.addWidget(title)
        self.status_lbl = QLabel("Read-only status view · session auth never saved")
        self.status_lbl.setObjectName("Muted")
        h_row.addWidget(self.status_lbl, 1)
        self.refresh_btn = QPushButton("Refresh")
        self.refresh_btn.setObjectName("Primary")
        self.refresh_btn.clicked.connect(self.refresh_keys)
        h_row.addWidget(self.refresh_btn)
        root.addWidget(header)

        # Session-only step-up auth bar (never persisted)
        auth_bar = QFrame()
        auth_bar.setObjectName("SummaryBar")
        a_row = QHBoxLayout(auth_bar)
        a_row.setContentsMargins(16, 8, 16, 8)
        a_row.setSpacing(10)
        a_row.addWidget(QLabel("Session Password:"))
        self.pw_input = QLineEdit()
        self.pw_input.setEchoMode(QLineEdit.Password)
        self.pw_input.setPlaceholderText("Typed per session (never stored)")
        a_row.addWidget(self.pw_input, 1)
        a_row.addWidget(QLabel("TOTP:"))
        self.totp_input = QLineEdit()
        self.totp_input.setMaxLength(6)
        self.totp_input.setMaximumWidth(95)
        self.totp_input.setPlaceholderText("123456")
        a_row.addWidget(self.totp_input)
        self.clear_auth_btn = QPushButton("Clear Session Auth")
        self.clear_auth_btn.clicked.connect(self.clear_session_auth)
        a_row.addWidget(self.clear_auth_btn)
        root.addWidget(auth_bar)

        # Key ledger table (multi-row selection for batch preview)
        self.table = QTableWidget(0, 9)
        self.table.setHorizontalHeaderLabels([
            "Site", "Label", "Type", "Prefix", "Created", "Expires",
            "Last Used", "State", "Attention Flags",
        ])
        self.table.setEditTriggers(QTableWidget.NoEditTriggers)
        self.table.setSelectionBehavior(QTableWidget.SelectRows)
        self.table.setSelectionMode(QTableWidget.ExtendedSelection)
        self.table.verticalHeader().setVisible(False)
        hdr = self.table.horizontalHeader()
        hdr.setSectionResizeMode(QHeaderView.ResizeToContents)
        hdr.setSectionResizeMode(8, QHeaderView.Stretch)
        self.table.itemSelectionChanged.connect(self._sync_selection_actions)
        root.addWidget(self.table, 1)

        # Create + Batch Preview / Confirm drawer
        actions = QFrame()
        actions.setObjectName("SummaryBar")
        p_col = QVBoxLayout(actions)
        p_col.setContentsMargins(16, 10, 16, 12)
        p_col.setSpacing(8)

        create_row = QHBoxLayout()
        create_row.addWidget(QLabel("New key for site:"))
        self.create_site_edit = QLineEdit("https://foundtextures.example")
        create_row.addWidget(self.create_site_edit, 1)
        create_row.addWidget(QLabel("Tool type:"))
        self.create_type_combo = QComboBox()
        for t in ("sybu", "suyb", "smackpress", "gyss", "flkrfckr", "ohsnap", "hub"):
            self.create_type_combo.addItem(t, t)
        create_row.addWidget(self.create_type_combo)
        self.create_btn = QPushButton("Create Key → Vault")
        self.create_btn.setObjectName("Primary")
        self.create_btn.clicked.connect(self.create_key_to_vault)
        create_row.addWidget(self.create_btn)

        self.preview_revoke_btn = QPushButton("Preview Revoke Selected")
        self.preview_revoke_btn.clicked.connect(lambda: self.preview_batch("revoke"))
        create_row.addWidget(self.preview_revoke_btn)

        self.preview_delete_btn = QPushButton("Preview Delete Selected (Revoked Only)")
        self.preview_delete_btn.setEnabled(False)
        self.preview_delete_btn.clicked.connect(lambda: self.preview_batch("delete"))
        create_row.addWidget(self.preview_delete_btn)
        p_col.addLayout(create_row)

        self.preview_list = QListWidget()
        self.preview_list.setMaximumHeight(110)
        p_col.addWidget(self.preview_list)

        confirm_row = QHBoxLayout()
        self.confirm_prompt_lbl = QLabel("Select keys and preview a batch action:")
        confirm_row.addWidget(self.confirm_prompt_lbl)
        self.confirm_input = QLineEdit()
        self.confirm_input.setPlaceholderText("Typed confirmation required")
        self.confirm_input.textChanged.connect(self._sync_execute_btn)
        confirm_row.addWidget(self.confirm_input, 1)
        self.execute_btn = QPushButton("Execute Batch")
        self.execute_btn.setObjectName("Danger")
        self.execute_btn.setEnabled(False)
        self.execute_btn.clicked.connect(self.execute_batch)
        confirm_row.addWidget(self.execute_btn)
        p_col.addLayout(confirm_row)

        root.addWidget(actions)
        self.setCentralWidget(central)
        self.refresh_keys()

    def clear_session_auth(self):
        self.pw_input.clear()
        self.totp_input.clear()

    def closeEvent(self, event):
        self.clear_session_auth()
        super().closeEvent(event)

    def refresh_keys(self):
        self._records = self._provider.list_keys()
        self.table.setRowCount(len(self._records))
        for row, rec in enumerate(self._records):
            vals = (
                rec.site_name,
                rec.label,
                rec.key_type,
                rec.key_prefix,
                rec.created_at,
                rec.expires_at or "Never",
                rec.last_used_at or "Never",
                "ACTIVE" if rec.is_active else "REVOKED",
                ", ".join(rec.flags) if rec.flags else "OK",
            )
            for col, text in enumerate(vals):
                self.table.setItem(row, col, QTableWidgetItem(text))
        self._sync_selection_actions()

    def selected_records(self) -> list[SiteKeyRecord]:
        rows = sorted({idx.row() for idx in self.table.selectionModel().selectedRows()})
        return [self._records[r] for r in rows if 0 <= r < len(self._records)]

    def _sync_selection_actions(self):
        sel = self.selected_records()
        # SS-1400: Delete is ONLY offered when every selected key is already revoked.
        can_delete = bool(sel) and all(not r.is_active for r in sel)
        self.preview_delete_btn.setEnabled(can_delete)
        self.preview_revoke_btn.setEnabled(bool(sel) and any(r.is_active for r in sel))

    def create_key_to_vault(self) -> str:
        url = self.create_site_edit.text().strip()
        ktype = str(self.create_type_combo.currentData() or "sybu")
        try:
            rec = self._provider.create_key(
                url, url, ktype, self.pw_input.text(), self.totp_input.text()
            )
        except Exception as exc:  # noqa: BLE001
            self.status_lbl.setText(f"Create refused: {exc}")
            return ""
        append_action_record(
            action="create",
            site_url=rec.site_url,
            key_id=rec.key_id,
            key_type=rec.key_type,
            key_prefix=rec.key_prefix,
            outcome="stored_in_vault",
            log_path=self._log_path,
        )
        # Never display raw key on screen — only prefix + confirmation.
        self.status_lbl.setText(
            f"Created {rec.key_type} key ({rec.key_prefix}…) and wrote straight to {rec.key_type} vault entry."
        )
        self.refresh_keys()
        return rec.key_prefix

    def preview_batch(self, action: str) -> list[SiteKeyRecord]:
        sel = self.selected_records()
        self._batch_action = action
        if action == "delete":
            # SS-1400: refuse active keys from delete batch
            self._batch_items = [r for r in sel if not r.is_active]
        else:
            self._batch_items = [r for r in sel if r.is_active]

        self.preview_list.clear()
        for r in self._batch_items:
            self.preview_list.addItem(
                f"{action.upper()} · {r.site_name} · {r.key_type} ({r.key_prefix}…)"
            )
        n = len(self._batch_items)
        verb = "DELETE" if action == "delete" else "REVOKE"
        self.confirm_prompt_lbl.setText(f"Type {verb} {n} KEYS to confirm:")
        self.confirm_input.clear()
        self._sync_execute_btn()
        return list(self._batch_items)

    def _expected_confirm(self) -> str:
        verb = "DELETE" if self._batch_action == "delete" else "REVOKE"
        return f"{verb} {len(self._batch_items)} KEYS"

    def _sync_execute_btn(self):
        ok = (
            bool(self._batch_items)
            and self.confirm_input.text().strip() == self._expected_confirm()
        )
        self.execute_btn.setEnabled(ok)

    def execute_batch(self) -> int:
        if not self._batch_items or self.confirm_input.text().strip() != self._expected_confirm():
            return 0
        pw = self.pw_input.text()
        totp = self.totp_input.text()
        count = 0
        for rec in list(self._batch_items):
            try:
                if self._batch_action == "delete":
                    self._provider.delete_key(rec.key_id, pw, totp)
                else:
                    self._provider.revoke_key(rec.key_id, pw, totp)
                append_action_record(
                    action=self._batch_action,
                    site_url=rec.site_url,
                    key_id=rec.key_id,
                    key_type=rec.key_type,
                    key_prefix=rec.key_prefix,
                    outcome="ok",
                    log_path=self._log_path,
                )
                count += 1
            except Exception as exc:  # noqa: BLE001
                self.status_lbl.setText(f"{self._batch_action} refused: {exc}")
                break
        self._batch_items = []
        self.preview_list.clear()
        self.confirm_input.clear()
        self.refresh_keys()
        return count


# ===== SNAPSMACK EOF =====
